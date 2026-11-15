<?php

declare(strict_types=1);

namespace Korin\Gedcom\Writer;

use Korin\Gedcom\Io\Codec\Codec;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;
use Korin\Gedcom\WriteOptions;

final class Writer
{
    public function serialize(Document $document, WriteOptions $options = new WriteOptions()): string
    {
        $encoding = $options->encoding ?? $document->encoding();
        $normalize = $options->mode === WriteMode::Normalize;
        // Original bytes are kept only in preserve mode and in their own encoding.
        $reencode = !$normalize && $encoding !== $document->encoding();
        $verbatim = !$normalize && !$reencode;
        // Normalizing UTF-8 to UTF-8, an unchanged line already in its normal form is kept as it is.
        $canonical = $normalize && $encoding === Encoding::Utf8 && $document->encoding() === Encoding::Utf8;
        $undecodable = $canonical ? $this->undecodableLines($document) : [];
        $codec = Codecs::for($encoding);
        $lineEnding = $options->lineEnding ?? $document->lineEnding();
        $eol = $encoding->ascii($lineEnding);
        // 7.0 has no CONC and no line length limit.
        $limit = $document->version()->escapesOnlyLeadingAt() ? null : $options->maxLineLength - strlen($lineEnding);
        $measure = $this->measure($encoding, $codec);
        $bom = $options->bom ?? ($normalize || $reencode ? self::bomByDefault($document->version(), $encoding) : $document->hasBom());

        $out = $bom ? $encoding->bom() : '';
        $pendingEol = null;
        $lastIsNew = false;
        foreach ($document->walk() as $id) {
            if ($pendingEol !== null) {
                // A line that had no terminator (the last one in the input) is no longer last.
                $out .= $pendingEol === '' ? $eol : $pendingEol;
            }
            if ($verbatim) {
                $original = $document->originalBytes($id);
                if ($original !== null) {
                    [$content, $pendingEol] = $original;
                    $out .= $content;
                    $lastIsNew = false;
                    continue;
                }
            } elseif ($reencode) {
                $original = $document->originalText($id);
                if ($original !== null) {
                    $out .= $codec->encode($original[0]);
                    $pendingEol = $encoding->ascii($original[1]);
                    $lastIsNew = false;
                    continue;
                }
            } elseif ($canonical && ($line = $this->normalLine($document, $id, $lineEnding, $limit, $undecodable)) !== null) {
                $out .= $line;
                $pendingEol = $eol;
                $lastIsNew = false;
                continue;
            } elseif ($document->isInvalid($id)) {
                // A line that could not be parsed is written as it was read.
                $out .= $codec->encode($document->originalText($id)[0] ?? '');
                $pendingEol = $eol;
                continue;
            }
            $depth = $document->depthOf($id);
            $xref = $document->xrefOf($id);
            $tag = $document->tagOf($id);
            $payload = $document->payloadOf($id);
            $prefix = $depth . ($xref !== null ? ' ' . $xref : '') . ' ' . $tag;
            // CONC lines continue the value: children of the line, or siblings of a CONT/CONC line.
            $concPrefix = ($tag === 'CONT' || $tag === 'CONC' ? $depth : $depth + 1) . ' CONC ';
            $parts = $payload === null || $limit === null
                ? [$payload]
                : ConcSplitter::split($payload, $limit - strlen($prefix) - 1, $limit - strlen($concPrefix), $measure);
            $out .= $codec->encode($prefix . ($parts[0] !== null ? ' ' . $parts[0] : ''));
            $ending = $normalize ? $eol : $document->originalEol($id);
            $pendingEol = $ending;
            foreach (array_slice($parts, 1) as $part) {
                $out .= ($pendingEol === '' ? $eol : $pendingEol) . $codec->encode($concPrefix . $part);
                $pendingEol = $eol;
            }
            if (count($parts) > 1) {
                $pendingEol = $ending === '' ? '' : $eol;
            }
            $lastIsNew = $document->lineOf($id) === 0;
        }
        // Keep a missing final terminator only when the input had none; a normalized file always has one.
        if ($pendingEol !== null) {
            $out .= $pendingEol === '' && ($lastIsNew || $normalize) ? $eol : $pendingEol;
        }

        return $out;
    }

    /**
     * The original content of a UTF-8 line when writing it anew would give the same bytes: one
     * space between level, xref, tag and value, the level of its depth, the line terminator of
     * the output, short enough for the 5.x limit, and decoded without errors.
     *
     * @param array<int, true> $undecodable
     */
    private function normalLine(Document $document, int $id, string $lineEnding, ?int $limit, array $undecodable): ?string
    {
        $original = $document->originalLine($id);
        if ($original === null || $original[1] !== $lineEnding || $document->isInvalid($id) || isset($undecodable[$document->lineOf($id)])) {
            return null;
        }
        [$content, , $valueStart] = $original;
        $xref = $document->xrefOf($id);
        $prefix = $document->depthOf($id) . ($xref !== null ? ' ' . $xref : '') . ' ' . $document->tagOf($id);
        $normal = $valueStart === 0 ? strlen($content) === strlen($prefix) : $valueStart === strlen($prefix) + 1;

        return $normal && str_starts_with($content, $prefix) && ($limit === null || strlen($content) <= $limit) ? $content : null;
    }

    /** @return array<int, true> lines with bytes invalid in the document's encoding (KV1003) */
    private function undecodableLines(Document $document): array
    {
        $lines = [];
        foreach ($document->diagnostics() as $diagnostic) {
            if ($diagnostic->code === 'KV1003' && $diagnostic->line !== null) {
                $lines[$diagnostic->line] = true;
            }
        }

        return $lines;
    }

    /**
     * Whether a file written anew starts with a byte order mark (SPEC section 3): 7.0 and 5.5.5
     * always, any version in UTF-16, not 5.5 and 5.5.1 in UTF-8 (some old programs do not
     * recognize "0 HEAD" after a BOM). Encodings without a BOM have none.
     */
    public static function bomByDefault(Version $version, Encoding $encoding): bool
    {
        return $encoding->bom() !== ''
            && ($version === Version::V7_0 || $version === Version::V5_5_5 || $encoding->codeUnitSize() === 2);
    }

    /**
     * @return \Closure(string): int characters for UTF-8 and UTF-16, encoded bytes otherwise
     */
    private function measure(Encoding $encoding, Codec $codec): \Closure
    {
        if ($encoding === Encoding::Utf8 || $encoding->codeUnitSize() === 2) {
            return static fn(string $piece): int => (int) preg_match_all('/./su', $piece);
        }

        return static fn(string $piece): int => strlen($codec->encode($piece));
    }
}

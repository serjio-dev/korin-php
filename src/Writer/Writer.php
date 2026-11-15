<?php

declare(strict_types=1);

namespace Korin\Gedcom\Writer;

use Korin\Gedcom\Io\Codec\Codec;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\WriteOptions;

final class Writer
{
    public function serialize(Document $document, WriteOptions $options = new WriteOptions()): string
    {
        $encoding = $document->encoding();
        $codec = Codecs::for($encoding);
        $lineEnding = $options->lineEnding ?? $document->lineEnding();
        $eol = $encoding->ascii($lineEnding);
        // 7.0 has no CONC and no line length limit.
        $limit = $document->version()->escapesOnlyLeadingAt() ? null : $options->maxLineLength - strlen($lineEnding);
        $measure = $this->measure($encoding, $codec);

        $out = $document->hasBom() ? $encoding->bom() : '';
        $pendingEol = null;
        $lastIsNew = false;
        foreach ($document->walk() as $id) {
            if ($pendingEol !== null) {
                // A line that had no terminator (the last one in the input) is no longer last.
                $out .= $pendingEol === '' ? $eol : $pendingEol;
            }
            $original = $document->originalBytes($id);
            if ($original !== null) {
                [$content, $pendingEol] = $original;
                $out .= $content;
                $lastIsNew = false;
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
            $pendingEol = $document->originalEol($id);
            foreach (array_slice($parts, 1) as $part) {
                $out .= ($pendingEol === '' ? $eol : $pendingEol) . $codec->encode($concPrefix . $part);
                $pendingEol = $eol;
            }
            if (count($parts) > 1) {
                $pendingEol = $document->originalEol($id) === '' ? '' : $eol;
            }
            $lastIsNew = $document->lineOf($id) === 0;
        }
        // Keep a missing final terminator only when the input had none.
        if ($pendingEol !== null) {
            $out .= $pendingEol === '' && $lastIsNew ? $eol : $pendingEol;
        }

        return $out;
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

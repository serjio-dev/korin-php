<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Io\EncodingDetector;
use Korin\Gedcom\Io\LineSplitter;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Version;

/** Builds a lossless {@see Document} from raw bytes. */
final readonly class Parser
{
    /**
     * Level, optional xref, tag, optional value after exactly one delimiter.
     *
     * @internal also used by {@see RecordStream} to find where records start
     */
    public const LINE = '/^[ \t]*(\d{1,3})(?:[ \t]+(@[^@]+@))?[ \t]+([^ \t]+)(?: (.*))?$/s';

    public function __construct(private EncodingDetector $detector = new EncodingDetector()) {}

    public function parse(string $bytes, ReadOptions $options = new ReadOptions()): Document
    {
        $detection = $this->detector->detect($bytes, $options->encoding);
        $encoding = $detection->encoding;
        $codec = Codecs::for($encoding);
        $start = $detection->hasBom ? strlen($encoding->bom()) : 0;

        $document = new Document($options->version ?? Version::V5_5_1, $encoding, $detection->hasBom, "\n", $bytes, $detection->diagnostics);
        $eolCounts = $this->parseLines($document, $bytes, $start, $options);
        if ($eolCounts !== []) {
            arsort($eolCounts);
            $document->setLineEnding($codec->decode(array_key_first($eolCounts)) ?? "\n");
        }
        $this->checkStructure($document, $options);

        return $document;
    }

    /**
     * @internal one record of a stream ({@see RecordStream}): a document whose only record is
     * these lines, numbered from $firstLine. File-level checks (HEAD, TRLR, version) are the
     * stream's.
     *
     * @param array<string, int> $eolCounts line terminators seen so far, updated
     */
    public function parseRecord(string $bytes, Encoding $encoding, Version $version, bool $bom, string $lineEnding, int $firstLine, ReadOptions $options, array &$eolCounts = []): Document
    {
        $document = new Document($version, $encoding, $bom, $lineEnding, $bytes, lineBase: $firstLine - 1);
        foreach ($this->parseLines($document, $bytes, 0, $options) as $terminator => $count) {
            $eolCounts[$terminator] = ($eolCounts[$terminator] ?? 0) + $count;
        }

        return $document;
    }

    /** @return array<string, int> line terminators (raw bytes) by count */
    private function parseLines(Document $document, string $bytes, int $start, ReadOptions $options): array
    {
        $encoding = $document->encoding();
        $codec = Codecs::for($encoding);
        $eolCounts = [];
        /** @var list<int> $stack node id at each depth, index = depth + 1 */
        $stack = [0];
        $lineNumber = $document->lineBase();
        /** @var array<string, string> one string per distinct tag, shared by all its lines */
        $tags = [];

        foreach (LineSplitter::split($bytes, $encoding, $start) as [$offset, $length, $eol]) {
            ++$lineNumber;
            if ($eol > 0) {
                $terminator = substr($bytes, $offset + $length, $eol);
                $eolCounts[$terminator] = ($eolCounts[$terminator] ?? 0) + 1;
            }
            if ($length > $options->maxLineLength) {
                throw new ParseException("Line {$lineNumber} exceeds the limit of {$options->maxLineLength} bytes");
            }
            $raw = substr($bytes, $offset, $length);
            $text = $codec->decode($raw);
            if ($text === null) {
                $this->report($document, $options, new Diagnostic('KV1003', Severity::Error, "Bytes are not valid {$encoding->value}", $lineNumber));
                $text = $codec->decodeLossy($raw);
            }

            if (preg_match(self::LINE, $text, $m) !== 1) {
                $this->report($document, $options, $text === ''
                    ? new Diagnostic('KV1001', Severity::Info, 'Empty line', $lineNumber)
                    : new Diagnostic('KV1001', Severity::Warning, 'Line is not a valid GEDCOM line and is kept verbatim', $lineNumber));
                $document->addParsedLine((int) end($stack), null, '', null, 0, $offset, $eol, invalid: true);
                continue;
            }

            $level = (int) $m[1];
            $depth = count($stack) - 1;
            if ($level > $depth) {
                $this->report($document, $options, new Diagnostic('KV1002', Severity::Error, "Level {$level} does not follow level " . ($depth - 1), $lineNumber));
                $level = $depth;
            } else {
                array_splice($stack, $level + 1);
            }
            if ($level >= $options->maxDepth) {
                throw new ParseException("Line {$lineNumber} exceeds the nesting limit of {$options->maxDepth}");
            }
            $xref = $m[2] !== '' ? $m[2] : null;
            $value = $m[4] ?? null;
            $tag = $tags[$m[3]] ??= $m[3];
            $valueStart = $value === null ? 0 : strlen($text) - strlen($value);
            $stack[] = $document->addParsedLine($stack[$level], $xref, $tag, $value, $valueStart, $offset, $eol);
        }

        return $eolCounts;
    }

    private function checkStructure(Document $document, ReadOptions $options): void
    {
        $records = $document->records();
        $trailers = array_keys(array_filter($records, static fn(Node $r): bool => $r->tag() === 'TRLR'));
        $afterTrailer = $trailers === [] ? null : ($records[end($trailers) + 1] ?? null);
        $version = $this->checkFile($records[0] ?? null, $trailers !== [], $afterTrailer?->line(), $document->head(), $options, $document->addDiagnostic(...));
        if ($version !== null) {
            $document->setVersion($version);
        }
    }

    /**
     * @internal the checks of a whole file (KV1004–KV1008), shared with {@see RecordStream}
     *
     * @param ?int                      $afterTrailer line of the first record after the last TRLR; null when there is none
     * @param \Closure(Diagnostic): void $add
     *
     * @return ?Version the declared version; null when it is unknown or given in the options
     */
    public function checkFile(?Node $first, bool $hasTrailer, ?int $afterTrailer, ?Node $head, ReadOptions $options, \Closure $add): ?Version
    {
        $report = static function (Diagnostic $diagnostic) use ($options, $add): void {
            if ($options->strict && $diagnostic->severity === Severity::Error) {
                throw new ParseException((string) $diagnostic);
            }
            $add($diagnostic);
        };
        if ($first?->tag() !== 'HEAD') {
            $report(new Diagnostic('KV1004', Severity::Error, 'The first record is not HEAD', $first?->line()));
        }
        if (!$hasTrailer) {
            $report(new Diagnostic('KV1005', Severity::Error, 'TRLR is missing'));
        } elseif ($afterTrailer !== null) {
            $report(new Diagnostic('KV1006', Severity::Warning, 'Records after TRLR', $afterTrailer));
        }

        if ($options->version !== null) {
            return null;
        }
        $vers = $head?->first('GEDC')?->first('VERS');
        $declared = $vers?->value();
        if ($declared === null) {
            $report(new Diagnostic('KV1007', Severity::Warning, 'HEAD.GEDC.VERS is missing; read as 5.5.1', $head?->line(), 'HEAD/GEDC/VERS'));

            return null;
        }
        $version = Version::fromDeclared($declared);
        if ($version === null) {
            $report(new Diagnostic('KV1008', Severity::Warning, 'Unknown GEDCOM version; read as 5.5.1', $vers->line(), 'HEAD/GEDC/VERS'));
        }

        return $version;
    }

    private function report(Document $document, ReadOptions $options, Diagnostic $diagnostic): void
    {
        if ($options->strict && $diagnostic->severity === Severity::Error) {
            throw new ParseException((string) $diagnostic);
        }
        $document->addDiagnostic($diagnostic);
    }
}

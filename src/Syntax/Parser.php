<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\EncodingDetector;
use Korin\Gedcom\Io\LineSplitter;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Version;

/** Builds a lossless {@see Document} from raw bytes. */
final readonly class Parser
{
    /** Level, optional xref, tag, optional value after exactly one delimiter. */
    private const LINE = '/^[ \t]*(\d{1,3})(?:[ \t]+(@[^@]+@))?[ \t]+([^ \t]+)(?: (.*))?$/s';

    public function __construct(private EncodingDetector $detector = new EncodingDetector()) {}

    public function parse(string $bytes, ReadOptions $options = new ReadOptions()): Document
    {
        $detection = $this->detector->detect($bytes, $options->encoding);
        $encoding = $detection->encoding;
        $codec = Codecs::for($encoding);
        $start = $detection->hasBom ? strlen($encoding->bom()) : 0;

        $document = new Document($options->version ?? Version::V5_5_1, $encoding, $detection->hasBom, "\n", $bytes, $detection->diagnostics);
        $eolCounts = [];
        /** @var list<int> $stack node id at each depth, index = depth + 1 */
        $stack = [0];
        $lineNumber = 0;
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

        if ($eolCounts !== []) {
            arsort($eolCounts);
            $document->setLineEnding($codec->decode(array_key_first($eolCounts)) ?? "\n");
        }
        $this->checkStructure($document, $options);

        return $document;
    }

    private function checkStructure(Document $document, ReadOptions $options): void
    {
        $records = $document->records();
        if (($records[0] ?? null)?->tag() !== 'HEAD') {
            $this->report($document, $options, new Diagnostic('KV1004', Severity::Error, 'The first record is not HEAD', ($records[0] ?? null)?->line()));
        }
        $trailers = array_keys(array_filter($records, static fn(Node $r): bool => $r->tag() === 'TRLR'));
        if ($trailers === []) {
            $this->report($document, $options, new Diagnostic('KV1005', Severity::Error, 'TRLR is missing'));
        } elseif (end($trailers) !== array_key_last($records)) {
            $this->report($document, $options, new Diagnostic('KV1006', Severity::Warning, 'Records after TRLR', ($records[end($trailers) + 1] ?? null)?->line()));
        }

        if ($options->version !== null) {
            return;
        }
        $vers = $document->head()?->first('GEDC')?->first('VERS');
        $declared = $vers?->value();
        if ($declared === null) {
            $this->report($document, $options, new Diagnostic('KV1007', Severity::Warning, 'HEAD.GEDC.VERS is missing; read as 5.5.1', $document->head()?->line(), 'HEAD/GEDC/VERS'));

            return;
        }
        $version = Version::fromDeclared($declared);
        if ($version === null) {
            $this->report($document, $options, new Diagnostic('KV1008', Severity::Warning, 'Unknown GEDCOM version; read as 5.5.1', $vers->line(), 'HEAD/GEDC/VERS'));

            return;
        }
        $document->setVersion($version);
    }

    private function report(Document $document, ReadOptions $options, Diagnostic $diagnostic): void
    {
        if ($options->strict && $diagnostic->severity === Severity::Error) {
            throw new ParseException((string) $diagnostic);
        }
        $document->addDiagnostic($diagnostic);
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Io\Codec\Codec;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Detection;
use Korin\Gedcom\Io\EncodingDetector;
use Korin\Gedcom\Io\LineSplitter;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Version;

/**
 * A file read one {@see Record} at a time, in memory that does not grow with the file
 * (SPEC section 6, read modes). Records are parsed as {@see Parser} parses the whole file:
 * the same nodes, values and line diagnostics, which each record's document holds. The
 * encoding is detected from the BOM, HEAD.CHAR and the first 64 KB; bytes later in the file
 * that are invalid in it are reported on their line (KV1003), not decoded again.
 *
 * The version comes from the first HEAD: records before it are read as 5.5.1 (or the version
 * in the options). {@see self::diagnostics()} has those of the whole file (encoding, HEAD,
 * TRLR, version), complete once every record was read. A stream is read once.
 *
 * @implements \IteratorAggregate<int, Record>
 */
final class RecordStream implements \IteratorAggregate
{
    private const CHUNK_SIZE = 65536;

    /** A level-0 line, the only kind that ends a record; {@see Parser::LINE} confirms it. */
    private const LEVEL_ZERO = '/^[ \t]*0{1,3}[ \t]/';

    private ?Detection $detection = null;

    private string $buffer = '';

    private bool $eof = false;

    private bool $started = false;

    private Version $version;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /**
     * @internal use {@see \Korin\Gedcom\Gedcom::stream()}
     *
     * @param resource $handle read from its current position
     * @param bool     $close  whether the stream closes the handle when it ends
     */
    public function __construct(
        private readonly mixed $handle,
        private readonly ReadOptions $options = new ReadOptions(),
        private readonly bool $close = false,
        private readonly int $chunkSize = self::CHUNK_SIZE,
        private readonly Parser $parser = new Parser(),
        private readonly EncodingDetector $detector = new EncodingDetector(),
    ) {
        $this->version = $options->version ?? Version::V5_5_1;
    }

    public function encoding(): \Korin\Gedcom\Io\Encoding
    {
        return $this->detect()->encoding;
    }

    public function hasBom(): bool
    {
        return $this->detect()->hasBom;
    }

    /** The version of the records read so far: 5.5.1 (or the one in the options) until HEAD is read. */
    public function version(): Version
    {
        return $this->version;
    }

    /**
     * @return list<Diagnostic> diagnostics of the whole file: encoding detection, then, once the
     *                          last record was read, HEAD, TRLR and version (KV1004–KV1008)
     */
    public function diagnostics(): array
    {
        return [...$this->detect()->diagnostics, ...$this->diagnostics];
    }

    /** @return \Generator<int, Record> */
    public function getIterator(): \Generator
    {
        if ($this->started) {
            throw new \LogicException('A record stream can be read only once');
        }
        $this->started = true;
        try {
            yield from $this->records();
        } finally {
            if ($this->close && is_resource($this->handle)) {
                fclose($this->handle);
            }
        }
    }

    /** @return \Generator<int, Record> */
    private function records(): \Generator
    {
        $detection = $this->detect();
        $encoding = $detection->encoding;
        $codec = Codecs::for($encoding);
        $cr = $encoding->ascii("\r");
        $pos = $detection->hasBom ? strlen($encoding->bom()) : 0;
        /** @var array<string, int> $eolCounts */
        $eolCounts = [];
        $pending = '';
        $pendingLine = 0;
        $line = 0;
        // Whether a record is open: lines before the first GEDCOM line are records of their own, as in Parser.
        $open = false;
        $first = $head = null;
        $hasTrailer = false;
        $afterTrailer = null;

        $emit = function (string $bytes, int $firstLine) use ($encoding, $codec, $detection, &$eolCounts, &$first, &$head, &$hasTrailer, &$afterTrailer): Record {
            $document = $this->parser->parseRecord($bytes, $encoding, $this->version, $detection->hasBom && $first === null, "\n", $firstLine, $this->options, $eolCounts);
            arsort($eolCounts);
            $document->setLineEnding($eolCounts === [] ? "\n" : ($codec->decode(array_key_first($eolCounts)) ?? "\n"));
            $record = new Record($document);
            $node = $record->node();
            $first ??= $node;
            $tag = $node->tag();
            if ($tag === 'HEAD' && $head === null) {
                $head = $node;
                $declared = $this->options->version === null ? $node->first('GEDC')?->first('VERS')?->value() : null;
                $version = $declared === null ? null : Version::fromDeclared($declared);
                if ($version !== null) {
                    $this->version = $version;
                    $document->setVersion($version);
                }
            }
            if ($tag === 'TRLR') {
                $hasTrailer = true;
                $afterTrailer = null;
            } elseif ($hasTrailer) {
                $afterTrailer ??= $firstLine;
            }

            return $record;
        };

        while (true) {
            $kept = null;
            foreach (LineSplitter::split($this->buffer, $encoding, $pos) as [$offset, $length, $eol]) {
                $end = $offset + $length + $eol;
                // A line is complete at its terminator; a CR at the end of the buffer may be the start of CRLF.
                if (!$this->eof && ($eol === 0 || ($end === strlen($this->buffer) && substr($this->buffer, $offset + $length, $eol) === $cr))) {
                    $kept = $offset;
                    break;
                }
                ++$line;
                $raw = substr($this->buffer, $offset, $length);
                if (!$open || $this->startsRecord($raw, $encoding->codeUnitSize() === 2 ? $codec : null)) {
                    if ($pending !== '') {
                        yield $emit($pending, $pendingLine);
                    }
                    $pending = '';
                    $pendingLine = $line;
                    $open = $open || $this->isLine($raw, $encoding->codeUnitSize() === 2 ? $codec : null);
                }
                $pending .= substr($this->buffer, $offset, $end - $offset);
            }
            if ($this->eof) {
                break;
            }
            $this->buffer = $kept === null ? '' : substr($this->buffer, $kept);
            $pos = 0;
            if (strlen($this->buffer) > $this->options->maxLineLength + 4) {
                throw new ParseException('Line ' . ($line + 1) . " exceeds the limit of {$this->options->maxLineLength} bytes");
            }
            $this->read();
        }
        $this->buffer = '';
        if ($pending !== '') {
            yield $emit($pending, $pendingLine);
        }

        $this->parser->checkFile($first, $hasTrailer, $afterTrailer, $head, $this->options, function (Diagnostic $diagnostic): void {
            $this->diagnostics[] = $diagnostic;
        });
    }

    /** Whether a line inside a record starts the next one: a GEDCOM line of level 0. */
    private function startsRecord(string $raw, ?Codec $utf16): bool
    {
        $text = $utf16?->decodeLossy($raw) ?? $raw;

        return preg_match(self::LEVEL_ZERO, $text) === 1 && preg_match(Parser::LINE, $text) === 1;
    }

    /**
     * Whether the line is a GEDCOM line. ASCII-compatible encodings are matched on their bytes:
     * delimiters, digits and "@" are the same bytes decoded or not.
     */
    private function isLine(string $raw, ?Codec $utf16): bool
    {
        return preg_match(Parser::LINE, $utf16?->decodeLossy($raw) ?? $raw) === 1;
    }

    /** Detects the encoding on the first bytes, as {@see EncodingDetector} does on a whole file. */
    private function detect(): Detection
    {
        if ($this->detection === null) {
            while (strlen($this->buffer) <= EncodingDetector::SAMPLE_SIZE && !$this->eof) {
                $this->read();
            }
            $this->detection = $this->detector->detect($this->buffer, $this->options->encoding);
        }

        return $this->detection;
    }

    private function read(): void
    {
        $chunk = fread($this->handle, max(1, $this->chunkSize));
        if ($chunk === false || $chunk === '') {
            $this->eof = feof($this->handle) || $chunk === false;

            return;
        }
        $this->buffer .= $chunk;
    }
}

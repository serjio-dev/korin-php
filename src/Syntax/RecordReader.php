<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Io\Codec\Codec;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Detection;
use Korin\Gedcom\Io\EncodingDetector;
use Korin\Gedcom\Io\LineSplitter;
use Korin\Gedcom\ReadOptions;

/**
 * Splits a file read in chunks into the bytes of its records, without parsing them: where
 * {@see Parser} would start a record, so each piece parses as that record. Lines before the
 * first GEDCOM line are pieces of their own, as the parser makes them records. The encoding is
 * detected on the first 64 KB, as {@see EncodingDetector} does on a whole file.
 *
 * @internal shared by {@see RecordStream} and {@see IndexedDocument}
 */
final class RecordReader
{
    private const CHUNK_SIZE = 65536;

    /** A level-0 line, the only kind that ends a record; {@see Parser::LINE} confirms it. */
    private const LEVEL_ZERO = '/^[ \t]*0{1,3}[ \t]/';

    private ?Detection $detection = null;

    private string $buffer = '';

    private bool $eof = false;

    /** @var array<string, int> line terminators (raw bytes) of the lines split so far, by count */
    private array $eolCounts = [];

    /**
     * @param resource $handle read from its current position
     */
    public function __construct(
        private readonly mixed $handle,
        private readonly ReadOptions $options,
        private readonly int $chunkSize = self::CHUNK_SIZE,
        private readonly EncodingDetector $detector = new EncodingDetector(),
    ) {}

    /** Detects the encoding on the first bytes, reading them if needed. */
    public function detection(): Detection
    {
        if ($this->detection === null) {
            while (strlen($this->buffer) <= EncodingDetector::SAMPLE_SIZE && !$this->eof) {
                $this->read();
            }
            $this->detection = $this->detector->detect($this->buffer, $this->options->encoding);
        }

        return $this->detection;
    }

    /** @return array<string, int> */
    public function eolCounts(): array
    {
        return $this->eolCounts;
    }

    /** The dominant line terminator so far, decoded; "\n" before the first one. */
    public function lineEnding(): string
    {
        if ($this->eolCounts === []) {
            return "\n";
        }
        $counts = $this->eolCounts;
        arsort($counts);

        return Codecs::for($this->detection()->encoding)->decode(array_key_first($counts)) ?? "\n";
    }

    /**
     * @return \Generator<int, array{string, int, int, ?string, string}> bytes of a record, its
     *         offset from where the handle was (a BOM included), first line, xref and tag (decoded;
     *         tag '' for a line that is not a GEDCOM line)
     */
    public function records(): \Generator
    {
        $detection = $this->detection();
        $encoding = $detection->encoding;
        $codec = Codecs::for($encoding);
        $utf16 = $encoding->codeUnitSize() === 2 ? $codec : null;
        $cr = $encoding->ascii("\r");
        $pos = $detection->hasBom ? strlen($encoding->bom()) : 0;
        // File offset of the buffer's first byte.
        $base = 0;
        $pending = '';
        $pendingOffset = $pendingLine = 0;
        $pendingXref = null;
        $pendingTag = '';
        $line = 0;
        // Whether a record is open: lines before the first GEDCOM line are records of their own, as in Parser.
        $open = false;

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
                if ($eol > 0) {
                    $terminator = substr($this->buffer, $offset + $length, $eol);
                    $this->eolCounts[$terminator] = ($this->eolCounts[$terminator] ?? 0) + 1;
                }
                $raw = substr($this->buffer, $offset, $length);
                $match = $open ? $this->levelZero($raw, $utf16) : $this->match($raw, $utf16);
                if (!$open || $match !== null) {
                    if ($pending !== '') {
                        yield [$pending, $pendingOffset, $pendingLine, $pendingXref, $pendingTag];
                    }
                    $pending = '';
                    $pendingOffset = $base + $offset;
                    $pendingLine = $line;
                    $pendingXref = $match === null || $match[2] === '' ? null : $this->decode($match[2], $codec);
                    $pendingTag = $match === null ? '' : $this->decode($match[3], $codec);
                    $open = $open || $match !== null;
                }
                $pending .= substr($this->buffer, $offset, $end - $offset);
            }
            if ($this->eof) {
                break;
            }
            $consumed = $kept ?? strlen($this->buffer);
            $this->buffer = substr($this->buffer, $consumed);
            $base += $consumed;
            $pos = 0;
            if (strlen($this->buffer) > $this->options->maxLineLength + 4) {
                throw new ParseException('Line ' . ($line + 1) . " exceeds the limit of {$this->options->maxLineLength} bytes");
            }
            $this->read();
        }
        $this->buffer = '';
        if ($pending !== '') {
            yield [$pending, $pendingOffset, $pendingLine, $pendingXref, $pendingTag];
        }
    }

    /**
     * A line inside a record that starts the next one: a GEDCOM line of level 0.
     *
     * @return ?array<int, string> the match of {@see Parser::LINE}
     */
    private function levelZero(string $raw, ?Codec $utf16): ?array
    {
        $text = $utf16?->decodeLossy($raw) ?? $raw;

        return preg_match(self::LEVEL_ZERO, $text) === 1 && preg_match(Parser::LINE, $text, $m) === 1 ? $m : null;
    }

    /**
     * The match of a GEDCOM line. ASCII-compatible encodings are matched on their bytes:
     * delimiters, digits and "@" are the same bytes decoded or not.
     *
     * @return ?array<int, string>
     */
    private function match(string $raw, ?Codec $utf16): ?array
    {
        return preg_match(Parser::LINE, $utf16?->decodeLossy($raw) ?? $raw, $m) === 1 ? $m : null;
    }

    /** An xref or tag of the line as the parser decodes it. */
    private function decode(string $part, Codec $codec): string
    {
        return preg_match('/[\x80-\xFF]/', $part) === 1 && $this->detection()->encoding->codeUnitSize() === 1
            ? ($codec->decode($part) ?? $codec->decodeLossy($part))
            : $part;
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

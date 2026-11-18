<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Io\Codec\Codec;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Detection;
use Korin\Gedcom\Io\Encoding;
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

    /** Whether lines are matched decoded: UTF-16, and ANSEL lines with combining marks ({@see self::text()}). */
    private bool $utf16 = false;

    private bool $ansel = false;

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
            $this->detection = $this->detector->detect($this->buffer, $this->options->encoding, $this->options->dialects);
        }

        return $this->detection;
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
        $this->utf16 = $encoding->codeUnitSize() === 2;
        $this->ansel = $encoding === Encoding::Ansel;
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
                if ($length > $this->options->maxLineLength) {
                    throw new ParseException("Line {$line} exceeds the limit of {$this->options->maxLineLength} bytes");
                }
                $raw = substr($this->buffer, $offset, $length);
                // Inlined text(): this runs for every line.
                $text = $this->utf16 || ($this->ansel && preg_match('/[\xE0-\xFE]/', $raw) === 1) ? $this->text($raw, $codec) : $raw;
                $match = $open
                    ? (preg_match(self::LEVEL_ZERO, $text) === 1 && preg_match(Parser::LINE, $text, $m) === 1 ? $m : null)
                    : (preg_match(Parser::LINE, $text, $m) === 1 ? $m : null);
                if (!$open || $match !== null) {
                    if ($pending !== '') {
                        yield [$pending, $pendingOffset, $pendingLine, $pendingXref, $pendingTag];
                    }
                    $pending = '';
                    $pendingOffset = $base + $offset;
                    $pendingLine = $line;
                    $pendingXref = $match === null || $match[2] === '' ? null : $this->decode($match[2], $codec, $text !== $raw);
                    $pendingTag = $match === null ? '' : $this->decode($match[3], $codec, $text !== $raw);
                    $open = $open || $match !== null;
                }
                $pending .= substr($this->buffer, $offset, $end - $offset);
                // Counted after the record before it was yielded: its line ending is that of the lines up to its end.
                if ($eol > 0) {
                    $terminator = substr($this->buffer, $offset + $length, $eol);
                    $this->eolCounts[$terminator] = ($this->eolCounts[$terminator] ?? 0) + 1;
                }
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
     * A line to match as the parser does, which decodes it first. ASCII-compatible encodings are
     * matched on their bytes: delimiters, digits and "@" are the same bytes decoded or not, and
     * so are other bytes, as far as the line is concerned. But an ANSEL combining mark goes with
     * the character after it, which may be a delimiter ("0\xE9 @I1@": a mark on the space).
     */
    private function text(string $raw, Codec $codec): string
    {
        return match (true) {
            $this->utf16 => $codec->decodeLossy($raw),
            $this->ansel && preg_match('/[\xE0-\xFE]/', $raw) === 1 => $codec->decode($raw) ?? $codec->decodeLossy($raw),
            default => $raw,
        };
    }

    /** An xref or tag of the line as the parser decodes it; $decoded when the line was matched decoded. */
    private function decode(string $part, Codec $codec, bool $decoded): string
    {
        return !$decoded && preg_match('/[\x80-\xFF]/', $part) === 1 ? ($codec->decode($part) ?? $codec->decodeLossy($part)) : $part;
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

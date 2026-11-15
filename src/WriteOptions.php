<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Writer\WriteMode;

final readonly class WriteOptions
{
    /** 5.5, 5.5.1 and 5.5.5: a line, terminator included, has at most 255 characters. */
    public const MAX_LINE_LENGTH_5 = 255;

    public function __construct(
        public WriteMode $mode = WriteMode::Preserve,
        public ?string $lineEnding = null,
        public int $maxLineLength = self::MAX_LINE_LENGTH_5,
    ) {}

    /** Unchanged lines byte for byte; changed and new lines by the version's rules. */
    public static function preserve(): self
    {
        return new self(WriteMode::Preserve);
    }

    /** Terminator for new and changed lines; null keeps the document's dominant one. */
    public function withLineEnding(?string $lineEnding): self
    {
        if ($lineEnding !== null && !in_array($lineEnding, ["\n", "\r\n", "\r"], true)) {
            throw new \InvalidArgumentException('Line ending must be LF, CRLF or CR');
        }

        return new self($this->mode, $lineEnding, $this->maxLineLength);
    }

    /**
     * Longest changed or new line in 5.x, terminator included; longer values are split into CONC lines.
     * Characters for UTF-8 and UTF-16, bytes for the other encodings. 7.0 has no CONC and no limit.
     */
    public function withMaxLineLength(int $maxLineLength): self
    {
        if ($maxLineLength < 1) {
            throw new \InvalidArgumentException('Maximum line length must be positive');
        }

        return new self($this->mode, $this->lineEnding, $maxLineLength);
    }
}

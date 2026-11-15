<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Writer\WriteMode;

final readonly class WriteOptions
{
    /** 5.5, 5.5.1 and 5.5.5: a line, terminator included, has at most 255 characters. */
    public const MAX_LINE_LENGTH_5 = 255;

    /**
     * @param ?Encoding $encoding null keeps the document's encoding
     * @param ?bool $bom null: as in the source in preserve mode (when the encoding is kept),
     *                   otherwise by the version's rules (SPEC section 3)
     */
    public function __construct(
        public WriteMode $mode = WriteMode::Preserve,
        public ?string $lineEnding = null,
        public int $maxLineLength = self::MAX_LINE_LENGTH_5,
        public ?Encoding $encoding = null,
        public ?bool $bom = null,
    ) {}

    /** Unchanged lines byte for byte; changed and new lines by the version's rules. */
    public static function preserve(): self
    {
        return new self(WriteMode::Preserve);
    }

    /** Every line written anew by the version's rules, with one line terminator. */
    public static function normalize(): self
    {
        return new self(WriteMode::Normalize);
    }

    /** Terminator for new and changed lines (every line when normalizing); null keeps the document's dominant one. */
    public function withLineEnding(?string $lineEnding): self
    {
        if ($lineEnding !== null && !in_array($lineEnding, ["\n", "\r\n", "\r"], true)) {
            throw new \InvalidArgumentException('Line ending must be LF, CRLF or CR');
        }

        return new self($this->mode, $lineEnding, $this->maxLineLength, $this->encoding, $this->bom);
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

        return new self($this->mode, $this->lineEnding, $maxLineLength, $this->encoding, $this->bom);
    }

    /**
     * Writes in another encoding; unchanged lines are re-encoded and keep their terminators.
     * HEAD.CHAR is not changed. A character the encoding lacks throws UnencodableCharacterException.
     */
    public function withEncoding(?Encoding $encoding): self
    {
        return new self($this->mode, $this->lineEnding, $this->maxLineLength, $encoding, $this->bom);
    }

    /** Whether the output starts with a byte order mark; null decides as described in the constructor. */
    public function withBom(?bool $bom): self
    {
        return new self($this->mode, $this->lineEnding, $this->maxLineLength, $this->encoding, $bom);
    }
}

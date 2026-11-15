<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Writer\WriteMode;

final readonly class WriteOptions
{
    public function __construct(
        public WriteMode $mode = WriteMode::Preserve,
        public ?string $lineEnding = null,
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

        return new self($this->mode, $lineEnding);
    }
}

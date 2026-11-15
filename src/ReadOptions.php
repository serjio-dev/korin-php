<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Io\Encoding;

final readonly class ReadOptions
{
    public function __construct(
        public bool $strict = false,
        public ?Encoding $encoding = null,
        public ?Version $version = null,
        public int $maxDepth = 100,
        public int $maxLineLength = 1_048_576,
    ) {}

    public function withStrict(bool $strict = true): self
    {
        return new self($strict, $this->encoding, $this->version, $this->maxDepth, $this->maxLineLength);
    }

    public function withEncoding(?Encoding $encoding): self
    {
        return new self($this->strict, $encoding, $this->version, $this->maxDepth, $this->maxLineLength);
    }

    public function withVersion(?Version $version): self
    {
        return new self($this->strict, $this->encoding, $version, $this->maxDepth, $this->maxLineLength);
    }

    public function withLimits(int $maxDepth, int $maxLineLength): self
    {
        return new self($this->strict, $this->encoding, $this->version, $maxDepth, $maxLineLength);
    }
}

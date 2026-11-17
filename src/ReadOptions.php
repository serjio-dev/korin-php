<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Io\Encoding;

final readonly class ReadOptions
{
    /**
     * @param int $maxArchiveSize      GEDZIP: the most bytes the entries of an archive may hold, uncompressed
     * @param int $maxCompressionRatio GEDZIP: the most an entry of more than 1 MiB may be compressed (a zip bomb is compressed by thousands)
     */
    public function __construct(
        public bool $strict = false,
        public ?Encoding $encoding = null,
        public ?Version $version = null,
        public int $maxDepth = 100,
        public int $maxLineLength = 1_048_576,
        public int $maxArchiveSize = 4_294_967_296,
        public int $maxCompressionRatio = 100,
        public bool $dialects = true,
    ) {}

    public function withStrict(bool $strict = true): self
    {
        return new self($strict, $this->encoding, $this->version, $this->maxDepth, $this->maxLineLength, $this->maxArchiveSize, $this->maxCompressionRatio, $this->dialects);
    }

    public function withEncoding(?Encoding $encoding): self
    {
        return new self($this->strict, $encoding, $this->version, $this->maxDepth, $this->maxLineLength, $this->maxArchiveSize, $this->maxCompressionRatio, $this->dialects);
    }

    public function withVersion(?Version $version): self
    {
        return new self($this->strict, $this->encoding, $version, $this->maxDepth, $this->maxLineLength, $this->maxArchiveSize, $this->maxCompressionRatio, $this->dialects);
    }

    public function withLimits(int $maxDepth, int $maxLineLength): self
    {
        return new self($this->strict, $this->encoding, $this->version, $maxDepth, $maxLineLength, $this->maxArchiveSize, $this->maxCompressionRatio, $this->dialects);
    }

    /** GEDZIP limits against zip bombs: total uncompressed size, and compression ratio of an entry over 1 MiB. */
    public function withArchiveLimits(int $maxArchiveSize, int $maxCompressionRatio): self
    {
        return new self($this->strict, $this->encoding, $this->version, $this->maxDepth, $this->maxLineLength, $maxArchiveSize, $maxCompressionRatio, $this->dialects);
    }

    /** Whether documented mistakes of programs are corrected (KV1010; docs/dialects.md): on by default. */
    public function withDialects(bool $dialects = true): self
    {
        return new self($this->strict, $this->encoding, $this->version, $this->maxDepth, $this->maxLineLength, $this->maxArchiveSize, $this->maxCompressionRatio, $dialects);
    }
}

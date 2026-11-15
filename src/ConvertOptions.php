<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Conversion\LossPolicy;

final readonly class ConvertOptions
{
    public function __construct(
        public LossPolicy $policy = LossPolicy::PreserveAsExtension,
    ) {}

    /** What to do with data the target version cannot hold; see LossPolicy. */
    public function withPolicy(LossPolicy $policy): self
    {
        return new self($policy);
    }
}

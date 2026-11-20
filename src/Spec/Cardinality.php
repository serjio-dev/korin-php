<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

/**
 * How many times a substructure may occur, e.g. {0:1}, {1:M}.
 *
 * @internal
 */
final readonly class Cardinality implements \Stringable
{
    /** @param ?int $max null for "M" (unbounded) */
    public function __construct(
        public int $min,
        public ?int $max,
    ) {}

    public function isRequired(): bool
    {
        return $this->min > 0;
    }

    public function __toString(): string
    {
        return '{' . $this->min . ':' . ($this->max ?? 'M') . '}';
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support\Conformance;

/** A line of a generated GEDCOM file with its substructures; mutable, so cases can change one place. */
final class Line
{
    /** @var list<Line> */
    public array $children = [];

    public function __construct(
        public string $tag,
        public ?string $value = null,
        public ?string $xref = null,
    ) {}

    public function child(string $tag): ?self
    {
        foreach ($this->children as $child) {
            if ($child->tag === $tag) {
                return $child;
            }
        }

        return null;
    }

    public function withoutChildren(string $tag): void
    {
        $this->children = array_values(array_filter($this->children, static fn(self $child): bool => $child->tag !== $tag));
    }
}

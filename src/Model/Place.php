<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;

/**
 * The value of a PLAC: its jurisdictions from the smallest to the largest, "Полтава, Україна".
 * The same in every version. A part may be empty: its place in the list counts (PLAC.FORM names
 * the parts, a substructure).
 */
final readonly class Place implements \Stringable
{
    /** @param list<string> $parts */
    public function __construct(public array $parts)
    {
        foreach ($parts as $part) {
            if (str_contains($part, ',') || preg_match('/[\x00-\x1F]/', $part) === 1) {
                throw new InvalidValueException('A place part has neither commas nor control characters');
            }
        }
        if ($parts === []) {
            throw new InvalidValueException('A place has at least one part');
        }
    }

    /** "Полтава, Україна", the parts separated by commas; spaces around them are not part of them. */
    public static function of(string $value): self
    {
        return new self(array_map(static fn(string $part): string => trim($part, ' '), explode(',', $value)));
    }

    /** The smallest jurisdiction that is named: "Полтава". */
    public function name(): string
    {
        foreach ($this->parts as $part) {
            if ($part !== '') {
                return $part;
            }
        }

        return '';
    }

    /** The PLAC value: the parts separated by ", ". */
    public function toGedcom(): string
    {
        return implode(', ', $this->parts);
    }

    public function __toString(): string
    {
        return $this->toGedcom();
    }
}

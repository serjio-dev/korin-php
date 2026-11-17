<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Version;

/**
 * An age at an event: a duration in years, months, weeks and days, "<" or ">" before it, or a
 * 5.x word (CHILD, INFANT, STILLBORN), and a phrase. The same value object for every version:
 * {@see self::parse()} reads the grammar of a version, {@see self::toGedcom()} writes it. The
 * phrase is the 7.0 PHRASE substructure; 5.x has none.
 */
final readonly class Age implements \Stringable
{
    public const CHILD = 'CHILD';
    public const INFANT = 'INFANT';
    public const STILLBORN = 'STILLBORN';

    public function __construct(
        public ?int $years = null,
        public ?int $months = null,
        public ?int $weeks = null,
        public ?int $days = null,
        public ?AgeBound $bound = null,
        public ?string $word = null,
        public ?string $phrase = null,
    ) {
        $durations = array_filter([$years, $months, $weeks, $days], static fn(?int $n): bool => $n !== null);
        if (array_filter($durations, static fn(int $n): bool => $n < 0) !== [] || $phrase === ''
            || ($word !== null && ($durations !== [] || $bound !== null || !in_array($word, [self::CHILD, self::INFANT, self::STILLBORN], true)))
            || ($bound !== null && $durations === [])) {
            throw new InvalidValueException('Not an age: a duration with an optional bound, or one of CHILD, INFANT, STILLBORN');
        }
    }

    /** @throws InvalidValueException when the value is not a valid age of the version */
    public static function parse(string $value, Version $version = Version::V7_0): self
    {
        return self::tryParse($value, $version) ?? throw new InvalidValueException("Not a valid GEDCOM {$version->value} age");
    }

    public static function tryParse(string $value, Version $version = Version::V7_0): ?self
    {
        if (!Grammars::isAge($version, $value)) {
            return null;
        }
        $upper = strtoupper(trim($value));
        if (in_array($upper, [self::CHILD, self::INFANT, self::STILLBORN], true)) {
            return new self(word: $upper);
        }
        preg_match_all('/([0-9]+)([ymwd])/i', $value, $parts, PREG_SET_ORDER);
        $units = [];
        foreach ($parts as [, $number, $unit]) {
            $units[strtolower($unit)] = (int) $number;
        }
        $bound = preg_match('/^([<>])/', $value, $m) === 1 ? AgeBound::from($m[1]) : null;

        return new self($units['y'] ?? null, $units['m'] ?? null, $units['w'] ?? null, $units['d'] ?? null, $bound);
    }

    public function withPhrase(?string $phrase): self
    {
        return new self($this->years, $this->months, $this->weeks, $this->days, $this->bound, $this->word, $phrase === '' ? null : $phrase);
    }

    /** No duration and no word: a 7.0 age that is only a phrase. */
    public function isEmpty(): bool
    {
        return $this->word === null && $this->years === null && $this->months === null && $this->weeks === null && $this->days === null;
    }

    /**
     * The value as the version writes it: "< 8y" in 7.0 and 5.5.5, "<8y" in 5.5 and 5.5.1. The
     * phrase is not part of it.
     *
     * @throws InvalidValueException when the version cannot write it: a word in 7.0, weeks or
     *         an empty age in 5.x
     */
    public function toGedcom(Version $version = Version::V7_0): string
    {
        if ($this->word !== null) {
            $value = $this->word;
        } else {
            $parts = [];
            foreach (['y' => $this->years, 'm' => $this->months, 'w' => $this->weeks, 'd' => $this->days] as $unit => $number) {
                if ($number !== null) {
                    $parts[] = $number . $unit;
                }
            }
            $space = $version === Version::V5_5 || $version === Version::V5_5_1 ? '' : ' ';
            $value = ($this->bound === null ? '' : $this->bound->value . $space) . implode(' ', $parts);
        }
        if (!Grammars::isAge($version, $value)) {
            throw new InvalidValueException("The age cannot be written in GEDCOM {$version->value}");
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->toGedcom();
    }
}

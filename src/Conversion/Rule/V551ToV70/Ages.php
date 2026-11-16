<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * Ages by the 7.0 grammar: lowercase units in the order y m w d, one space between parts and
 * after "<" or ">" ("<0Y" → "< 0y", "11m 99y" → "99y 11m"); a number without a unit is years,
 * as most 5.5.1 programs read it. CHILD, INFANT and STILLBORN become "< 8y", "< 1y" and "0y"
 * with the word as PHRASE; anything else becomes PHRASE of an empty age.
 *
 * @internal
 */
final class Ages implements NodeRule
{
    private const WORDS = ['CHILD' => '< 8y', 'INFANT' => '< 1y', 'STILLBORN' => '0y'];

    private const UNITS = ['y' => 0, 'm' => 1, 'w' => 2, 'd' => 3];

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['AGE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $value = $context->value($id);
        if ($value === null || $value === '' || $context->sourceElement($id) !== '<AGE_AT_EVENT>') {
            return true;
        }
        $converted = $this->convert($value);
        if ($converted === null) {
            return true;
        }
        [$age, $phrase] = $converted;
        $context->setValue($id, $age === '' ? null : $age);
        if ($phrase !== null) {
            $context->addText($id, 'PHRASE', $phrase);
        }

        return true;
    }

    /** @return ?array{string, ?string} the 7.0 age ('' for none) and PHRASE; null when nothing changes */
    public function convert(string $value): ?array
    {
        $word = self::WORDS[strtoupper(trim($value))] ?? null;
        if ($word !== null) {
            return [$word, $value];
        }
        $age = $this->age($value);
        if ($age === null) {
            return ['', $value];
        }

        return $age === $value ? null : [$age, null];
    }

    private function age(string $value): ?string
    {
        if (preg_match('/^ *+([<>])? *+((?:[0-9]++ *+[YMWDymwd]? *+)++)$/', $value, $m) !== 1
            || preg_match_all('/([0-9]+) *([YMWDymwd]?)/', $m[2], $parts, PREG_SET_ORDER) === false) {
            return null;
        }
        $durations = [];
        foreach ($parts as [, $number, $unit]) {
            if ($unit === '' && count($parts) > 1) {
                return null;
            }
            $unit = $unit === '' ? 'y' : strtolower($unit);
            if (isset($durations[self::UNITS[$unit]])) {
                return null;
            }
            $durations[self::UNITS[$unit]] = $number . $unit;
        }
        ksort($durations);

        return ($m[1] === '' ? '' : $m[1] . ' ') . implode(' ', $durations);
    }
}

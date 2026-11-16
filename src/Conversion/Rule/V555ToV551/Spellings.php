<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V555ToV551;

/**
 * The 5.5.1 spelling of 5.5.5 values (for Rule\Values): ages without the space after < and >
 * ("< 1y" → "<1y"), BCE and BC as B.C. ("1000 BCE" → "1000 B.C."). Null when there is none:
 * 5.5.1 has no day and month without a year ("5 MAR"). years() takes back the zeroes 5.5.5
 * puts before short years, for every date.
 *
 * @internal
 */
final class Spellings
{
    public static function respell(string $grammar, string $value): ?string
    {
        return match ($grammar) {
            'AGE_AT_EVENT' => (string) preg_replace('/^([<>]) +/', '$1', $value),
            'DATE_VALUE', 'DATE_PERIOD', 'DATE_EXACT' => (string) preg_replace('/ (?:BCE|BC)(?= |$)/i', ' B.C.', $value),
            default => null,
        };
    }

    /** A date without the leading zeroes of its years ("5 JAN 050" → "5 JAN 50"); days have none in 5.5.5. */
    public static function years(string $value): string
    {
        return str_contains($value, '(') ? $value : (string) preg_replace('/(?<![^ ])0+(?=[1-9][0-9]*(?:$| |\/))/', '', $value);
    }
}

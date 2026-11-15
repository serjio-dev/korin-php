<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

/**
 * The GEDCOM 5.x date elements DATE_VALUE, DATE_PERIOD and DATE_EXACT (5.5.1 pages 45-48, 65):
 * calendar escapes such as "@#DJULIAN@", "B.C." after any year (5.5: "A (B.C.) appended to the
 * <YEAR>"; 5.5.1 writes it only after a lone year, but "MAR 44 B.C." means the same in both),
 * dual years ("1699/00") in the Gregorian calendar, date phrases in parentheses. Keywords and months are
 * case-insensitive. @#DROMAN@ and @#DUNKNOWN@ are "for future definition": any day, word and
 * year is accepted.
 *
 * @internal
 */
final readonly class DateGrammar5
{
    public const VALID = 0;
    /** A day beyond the length of its month. */
    public const DAY_OUT_OF_RANGE = 1;
    /** A dual year whose second part is not the following year: "1701/99". */
    public const BAD_DUAL_YEAR = 2;
    /** Not a date of the grammar; outranks the others. */
    public const INVALID = 3;

    /** Calendar escape => element listing its months; null for calendars without a definition. */
    private const ESCAPES = [
        'GREGORIAN' => '<MONTH>',
        'JULIAN' => '<MONTH>',
        'HEBREW' => '<MONTH_HEBR>',
        'FRENCH R' => '<MONTH_FREN>',
        'ROMAN' => null,
        'UNKNOWN' => null,
    ];

    private const DATE = '/^(?:(?:([0-9]+) )?([A-Z][A-Z0-9_]*) )?([0-9]+)(?:\/([0-9]{2}))?( ?B\.C\.)?$/';

    /** @var array<string, array<string, true>> calendar => months */
    private array $months;

    /** @param callable(string): list<string> $enumeration values of an element, from the profile */
    public function __construct(callable $enumeration)
    {
        $months = [];
        foreach (self::ESCAPES as $calendar => $element) {
            if ($element !== null) {
                $months[$calendar] = array_fill_keys($enumeration($element), true);
            }
        }
        $this->months = $months;
    }

    /**
     *
     * @return self::VALID|self::DAY_OUT_OF_RANGE|self::BAD_DUAL_YEAR|self::INVALID
     */
    public function check(string $grammar, string $value): int
    {
        $value = strtoupper($value);

        return match ($grammar) {
            'DATE_EXACT' => preg_match('/^[0-9]+ [A-Z]+ [0-9]+(?:\/[0-9]{2})?$/', $value) === 1 ? $this->date($value) : self::INVALID,
            'DATE_PERIOD' => $this->period($value) ?? self::INVALID,
            default => $this->dateValue($value),
        };
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::BAD_DUAL_YEAR|self::INVALID */
    private function dateValue(string $value): int
    {
        if (preg_match('/^\(.+\)$/s', $value) === 1) {
            return self::VALID;
        }
        if (preg_match('/^INT (.+?) \(.+\)$/s', $value, $m) === 1
            || preg_match('/^(?:BET (.+?) AND (.+)|BEF (.+)|AFT (.+))$/', $value, $m) === 1
            || preg_match('/^(?:ABT|CAL|EST) (.+)$/', $value, $m) === 1) {
            return $this->dates(array_slice($m, 1));
        }

        return $this->period($value) ?? $this->date($value);
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::BAD_DUAL_YEAR|self::INVALID|null null when the value is not a period */
    private function period(string $value): ?int
    {
        return preg_match('/^(?:FROM (.+?)(?: TO (.+))?|TO (.+))$/', $value, $m) === 1 ? $this->dates(array_slice($m, 1)) : null;
    }

    /**
     * @param list<string> $dates empty strings are absent groups
     *
     * @return self::VALID|self::DAY_OUT_OF_RANGE|self::BAD_DUAL_YEAR|self::INVALID
     */
    private function dates(array $dates): int
    {
        $result = self::VALID;
        foreach ($dates as $date) {
            if ($date !== '') {
                $result = max($result, $this->date($date));
            }
        }

        return $result;
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::BAD_DUAL_YEAR|self::INVALID */
    private function date(string $date): int
    {
        $calendar = 'GREGORIAN';
        if (preg_match('/^@#D([A-Z ]+)@ (.+)$/', $date, $m) === 1) {
            [, $calendar, $date] = $m;
        }
        if (!array_key_exists($calendar, self::ESCAPES) || preg_match(self::DATE, $date, $m) !== 1) {
            return self::INVALID;
        }
        $months = $this->months[$calendar] ?? null;
        if ($months === null) {
            return self::VALID;
        }
        $day = $m[1];
        $month = $m[2];
        $year = (int) $m[3];
        $dual = $m[4] ?? '';
        $bc = ($m[5] ?? '') !== '';
        // Only Gregorian years can be dual; a day needs a month.
        if (($dual !== '' && $calendar !== 'GREGORIAN') || ($month !== '' && !isset($months[$month]))) {
            return self::INVALID;
        }
        if ($day !== '' && ((int) $day < 1 || (int) $day > DateGrammar::monthDays($calendar === 'FRENCH R' ? 'FRENCH_R' : $calendar, $month, $dual === '' ? $year : $year + 1, $bc))) {
            return self::DAY_OUT_OF_RANGE;
        }

        return $dual !== '' && (int) $dual !== ($year + 1) % 100 ? self::BAD_DUAL_YEAR : self::VALID;
    }
}

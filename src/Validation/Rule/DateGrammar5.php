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
 * 5.5.5 (DATE_GREG, DATE_JULN, YEAR, DAY, DUAL_STYLE_YEAR) is stricter and slightly different:
 * years of 3 or 4 digits, days without a leading zero, "BCE", "BC" or "B.C." after a space and
 * only after a lone Gregorian or Julian year, dual years in both of these calendars but only
 * with a month, a Gregorian day and month without a year ("23 AUG"), and no @#DROMAN@.
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

    private const DATE_555 = '/^(?:(?:([1-9][0-9]?) )?([A-Z]+) )?([0-9]{3,4})(?:\/([0-9]{2}))?( (?:BCE|BC|B\.C\.))?$/';

    /** 5.5.5 calendars with BCE and dual years. */
    private const PROLEPTIC_555 = ['GREGORIAN' => true, 'JULIAN' => true];

    /** @var array<string, array<string, true>> calendar => months */
    private array $months;

    /**
     * @param callable(string): list<string> $enumeration values of an element, from the profile
     * @param bool $v555 whether the 5.5.5 date grammar applies
     */
    public function __construct(callable $enumeration, private bool $v555 = false)
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
            'DATE_EXACT' => preg_match($this->v555 ? '/^[1-9][0-9]? [A-Z]+ [0-9]{3,4}$/' : '/^[0-9]+ [A-Z]+ [0-9]+(?:\/[0-9]{2})?$/', $value) === 1 ? $this->date($value) : self::INVALID,
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
        if (!array_key_exists($calendar, self::ESCAPES) || ($this->v555 && $calendar === 'ROMAN')) {
            return self::INVALID;
        }
        if ($this->v555 && isset($this->months[$calendar])) {
            return $this->date555($calendar, $date);
        }
        if (preg_match(self::DATE, $date, $m) !== 1) {
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

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::BAD_DUAL_YEAR|self::INVALID */
    private function date555(string $calendar, string $date): int
    {
        $months = $this->months[$calendar] ?? [];
        // <DAY> + space + <MONTH>: a Gregorian date without a year; 29 FEB is possible.
        if (preg_match('/^([1-9][0-9]?) ([A-Z]+)$/', $date, $m) === 1) {
            if ($calendar !== 'GREGORIAN' || !isset($months[$m[2]])) {
                return self::INVALID;
            }

            return (int) $m[1] > DateGrammar::monthDays($calendar, $m[2], 2000, false) ? self::DAY_OUT_OF_RANGE : self::VALID;
        }
        if (preg_match(self::DATE_555, $date, $m) !== 1) {
            return self::INVALID;
        }
        $day = $m[1];
        $month = $m[2];
        $year = (int) $m[3];
        $dual = $m[4] ?? '';
        $bce = ($m[5] ?? '') !== '';
        $proleptic = isset(self::PROLEPTIC_555[$calendar]);
        // BCE follows a lone year; a dual year needs a month; both only in Gregorian and Julian.
        if (($bce && ($month !== '' || !$proleptic)) || ($dual !== '' && ($month === '' || !$proleptic)) || ($month !== '' && !isset($months[$month]))) {
            return self::INVALID;
        }
        if ($day !== '' && (int) $day > DateGrammar::monthDays($calendar === 'FRENCH R' ? 'FRENCH_R' : $calendar, $month, $dual === '' ? $year : $year + 1, false)) {
            return self::DAY_OUT_OF_RANGE;
        }

        return $dual !== '' && (int) $dual !== ($year + 1) % 100 ? self::BAD_DUAL_YEAR : self::VALID;
    }
}

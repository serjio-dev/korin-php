<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Spec\JulianDays;

/**
 * The days a date value can mean, as Julian Day Numbers, for comparing dates: "1907" is
 * 1 JAN to 31 DEC 1907, "BEF 1907" has no first day, "BET 1900 AND 1910" spans both years.
 * Gregorian and Julian dates of every version are read (7.0 "JULIAN 1650", 5.x
 * "@#DJULIAN@ 1650", "1648/49", "44 BCE", "44 B.C."). Approximate dates (ABT, CAL, EST), date
 * phrases, other calendars and anything unreadable have no range: plausibility is only
 * reported when it is certain.
 *
 * @internal
 */
final class DateRange
{
    private const DATE = '/^(?:(GREGORIAN|JULIAN|@#DGREGORIAN@|@#DJULIAN@) )?(?:(?:([0-9]{1,2}) )?([A-Z]{3}) )?([0-9]{1,4})(?:\/([0-9]{2}))?( ?(?:BCE|BC|B\.C\.))?$/';

    /** @return ?array{int, int} first and last day; PHP_INT_MIN / PHP_INT_MAX for an open end */
    public static function of(string $value): ?array
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^INT (.+?) \(.*\)$/s', $value, $m) === 1) {
            $value = $m[1];
        }
        if (preg_match('/^(?:BET (.+?) AND (.+)|FROM (.+?) TO (.+))$/', $value, $m) === 1) {
            $first = self::date($m[1] !== '' ? $m[1] : $m[3]);
            $last = self::date($m[2] !== '' ? $m[2] : $m[4]);

            // A range that names its end first spans the same days.
            return $first === null || $last === null ? null : [min($first[0], $last[0]), max($first[1], $last[1])];
        }
        if (preg_match('/^(BEF|TO|AFT|FROM) (.+)$/', $value, $m) === 1) {
            $date = self::date($m[2]);

            return match (true) {
                $date === null => null,
                $m[1] === 'BEF' || $m[1] === 'TO' => [PHP_INT_MIN, $date[1]],
                default => [$date[0], PHP_INT_MAX],
            };
        }

        return self::date($value);
    }

    /** @return ?array{int, int} */
    private static function date(string $date): ?array
    {
        if (preg_match(self::DATE, $date, $m) !== 1) {
            return null;
        }
        $julian = str_contains($m[1], 'JULIAN');
        $year = (int) $m[4];
        if (($m[5] ?? '') !== '') {
            // "1648/49": the year that began on 1 January, the second one.
            $second = $year - $year % 100 + (int) $m[5];
            $year = $second < $year ? $second + 100 : $second;
        }
        if (($m[6] ?? '') !== '') {
            $year = 1 - $year;
        }
        if ($m[3] !== '' && !isset(JulianDays::MONTHS[$m[3]])) {
            return null;
        }

        return JulianDays::range($year, $m[3] === '' ? null : JulianDays::MONTHS[$m[3]], $m[2] === '' ? null : (int) $m[2], $julian);
    }
}

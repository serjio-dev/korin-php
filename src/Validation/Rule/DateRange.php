<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Model\Date;

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
    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    private const DATE = '/^(?:(GREGORIAN|JULIAN|@#DGREGORIAN@|@#DJULIAN@) )?(?:(?:([0-9]{1,2}) )?([A-Z]{3}) )?([0-9]{1,4})(?:\/([0-9]{2}))?( ?(?:BCE|BC|B\.C\.))?$/';

    /** Years before this one are not compared (and keep the arithmetic positive). */
    private const EARLIEST_YEAR = -4000;

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

            return $first === null || $last === null ? null : [$first[0], $last[1]];
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
        if ($year < self::EARLIEST_YEAR || ($m[3] !== '' && !isset(self::MONTHS[$m[3]]))) {
            return null;
        }
        if ($m[3] === '') {
            return [self::day($year, 1, 1, $julian), self::day($year + 1, 1, 1, $julian) - 1];
        }
        $month = self::MONTHS[$m[3]];
        $next = $month === 12 ? self::day($year + 1, 1, 1, $julian) : self::day($year, $month + 1, 1, $julian);
        if ($m[2] === '') {
            return [self::day($year, $month, 1, $julian), $next - 1];
        }
        // A day beyond the month (31 NOV, a KV3008 warning) counts as its last day.
        $day = min(self::day($year, $month, (int) $m[2], $julian), $next - 1);

        return [$day, $day];
    }

    private static function day(int $year, int $month, int $day, bool $julian): int
    {
        return Date::julianDay($year, $month, $day, $julian);
    }
}

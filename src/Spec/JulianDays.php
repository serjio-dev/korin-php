<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

/**
 * Gregorian and Julian dates as Julian Day Numbers, for comparing dates: shared by the model
 * ({@see \Korin\Gedcom\Model\Date::days()}) and the plausibility checks.
 *
 * @internal
 */
final class JulianDays
{
    public const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    /** Years outside these are not compared; they keep the arithmetic in integers and floored. */
    private const EARLIEST_YEAR = -4000;
    private const LATEST_YEAR = 9999;

    /**
     * The first and last day of a year, a month of it or a day; a day beyond its month counts as
     * the month's last day.
     *
     * @param int  $year   astronomical: 1 BCE is 0
     * @param ?int $month  1–12
     * @param bool $julian the Julian calendar, otherwise the Gregorian one
     *
     * @return ?array{int, int} null for a year that is not compared
     */
    public static function range(int $year, ?int $month, ?int $day, bool $julian): ?array
    {
        if ($year < self::EARLIEST_YEAR || $year > self::LATEST_YEAR) {
            return null;
        }
        if ($month === null) {
            return [self::day($year, 1, 1, $julian), self::day($year + 1, 1, 1, $julian) - 1];
        }
        $last = ($month === 12 ? self::day($year + 1, 1, 1, $julian) : self::day($year, $month + 1, 1, $julian)) - 1;
        if ($day === null) {
            return [self::day($year, $month, 1, $julian), $last];
        }
        $first = min(self::day($year, $month, $day, $julian), $last);

        return [$first, $first];
    }

    /** Julian Day Number of a date with an astronomical year. */
    public static function day(int $year, int $month, int $day, bool $julian): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;
        $days = $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4);

        return $julian ? $days - 32083 : $days - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }
}

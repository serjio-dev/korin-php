<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;

/**
 * One calendar date of a {@see GedcomDate}: a year, optionally a month and a day, in a
 * calendar. Calendars, months and epochs are GEDCOM tags, written the same for every version:
 * the 5.x escape "@#DFRENCH R@" is FRENCH_R, "B.C." is the epoch BCE. A 7.0 extension calendar,
 * month or epoch keeps its tag ("_MAYA"). A dual year ("1648/49", 5.x only) keeps both years.
 * A day and month without a year ("23 AUG") is a 5.5.5 Gregorian date. Whether the date exists
 * in its calendar is checked when a {@see GedcomDate} is parsed or written.
 */
final readonly class Date
{
    public const GREGORIAN = 'GREGORIAN';
    public const JULIAN = 'JULIAN';
    public const HEBREW = 'HEBREW';
    public const FRENCH_R = 'FRENCH_R';
    /** 5.x @#DROMAN@ and @#DUNKNOWN@, calendars "for future definition". */
    public const ROMAN = 'ROMAN';
    public const UNKNOWN = 'UNKNOWN';

    public const BCE = 'BCE';

    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    /**
     * @param ?int $dualYear the year by a 1 January new year: 1649 for "1648/49"
     */
    public function __construct(
        public ?int $year,
        public ?string $month = null,
        public ?int $day = null,
        public string $calendar = self::GREGORIAN,
        public ?string $epoch = null,
        public ?int $dualYear = null,
    ) {
        if (($year === null ? $day === null : $year < 0) || ($day !== null && ($month === null || $day < 1)) || ($month !== null && preg_match('/^_?[A-Z][A-Z0-9_]*$/', $month) !== 1)
            || preg_match('/^_?[A-Z][A-Z0-9_]*$/', $calendar) !== 1 || ($epoch !== null && preg_match('/^_?[A-Z][A-Z0-9_]*$/', $epoch) !== 1)
            || ($dualYear !== null && ($year === null || $dualYear <= $year || $dualYear - $year > 99))) {
            throw new InvalidValueException('Not a date: a year, a month tag, a day of a month and calendar and epoch tags');
        }
    }

    public static function of(?int $year, ?string $month = null, ?int $day = null, string $calendar = self::GREGORIAN): self
    {
        return new self($year, $month, $day, $calendar);
    }

    public function isBce(): bool
    {
        return $this->epoch === self::BCE;
    }

    /**
     * The days the date can mean, as Julian Day Numbers: "1907" is 1 JAN to 31 DEC 1907. Only
     * for Gregorian and Julian dates with a year and a standard month; a dual year counts as its second year.
     *
     * @return ?array{int, int}
     */
    public function days(): ?array
    {
        $julian = $this->calendar === self::JULIAN;
        if ((!$julian && $this->calendar !== self::GREGORIAN) || ($this->epoch !== null && !$this->isBce())
            || ($this->month !== null && !isset(self::MONTHS[$this->month])) || $this->year === null) {
            return null;
        }
        $year = $this->dualYear ?? $this->year;
        // There is no year 0: 1 BCE is the astronomical year 0.
        $year = $this->isBce() ? 1 - $year : $year;
        if ($this->month === null) {
            return [self::julianDay($year, 1, 1, $julian), self::julianDay($year + 1, 1, 1, $julian) - 1];
        }
        $month = self::MONTHS[$this->month];
        $last = ($month === 12 ? self::julianDay($year + 1, 1, 1, $julian) : self::julianDay($year, $month + 1, 1, $julian)) - 1;
        if ($this->day === null) {
            return [self::julianDay($year, $month, 1, $julian), $last];
        }
        $day = min(self::julianDay($year, $month, $this->day, $julian), $last);

        return [$day, $day];
    }

    /** @internal Julian Day Number of a date with an astronomical year (1 BCE is 0) */
    public static function julianDay(int $year, int $month, int $day, bool $julian): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;
        $days = $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4);

        return $julian ? $days - 32083 : $days - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }
}

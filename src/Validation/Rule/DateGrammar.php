<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

/**
 * The GEDCOM 7.0 date types DateValue, DatePeriod and DateExact (specification section 2.6 and
 * appendix A). Months and epochs come from the profile's calendars; month lengths are known here
 * for the four standard calendars. Extension calendars must use extension months; their days are
 * not bounded.
 *
 * @internal
 */
final readonly class DateGrammar
{
    /** The payload follows the grammar and every day fits its month. */
    public const VALID = 0;
    /** The payload follows the grammar, but a day is beyond the length of its month. */
    public const DAY_OUT_OF_RANGE = 1;
    /** The payload does not follow the grammar; outranks DAY_OUT_OF_RANGE. */
    public const INVALID = 2;

    /** No known calendar has a month longer than this (section 2.6). */
    private const MAX_DAY = 36;

    /** One date: calendar, day, month, year, epoch; also read by {@see \Korin\Gedcom\Model\GedcomDate}. */
    public const DATE = '/^(?:(GREGORIAN|JULIAN|FRENCH_R|HEBREW|_[A-Z0-9_]+) )?(?:(?:([0-9]+) )?([A-Z][A-Z0-9_]*|_[A-Z0-9_]+) )?([0-9]+)(?: (BCE|_[A-Z0-9_]+))?$/';

    /** Longest month lengths, in days; February is checked against the year. */
    private const MONTH_DAYS = [
        'GREGORIAN' => ['JAN' => 31, 'FEB' => 29, 'MAR' => 31, 'APR' => 30, 'MAY' => 31, 'JUN' => 30, 'JUL' => 31, 'AUG' => 31, 'SEP' => 30, 'OCT' => 31, 'NOV' => 30, 'DEC' => 31],
        'HEBREW' => ['TSH' => 30, 'CSH' => 30, 'KSL' => 30, 'TVT' => 29, 'SHV' => 30, 'ADR' => 30, 'ADS' => 29, 'NSN' => 30, 'IYR' => 29, 'SVN' => 30, 'TMZ' => 29, 'AAV' => 30, 'ELL' => 29],
        'FRENCH_R' => ['VEND' => 30, 'BRUM' => 30, 'FRIM' => 30, 'NIVO' => 30, 'PLUV' => 30, 'VENT' => 30, 'GERM' => 30, 'FLOR' => 30, 'PRAI' => 30, 'MESS' => 30, 'THER' => 30, 'FRUC' => 30, 'COMP' => 6],
    ];

    /** @var array<string, array{months: array<string, true>, epochs: array<string, true>}> */
    private array $calendars;

    /** @param array<string, array{uri: string, months: list<string>, epochs: list<string>}> $calendars */
    public function __construct(array $calendars)
    {
        $this->calendars = array_map(static fn(array $calendar): array => [
            'months' => array_fill_keys($calendar['months'], true),
            'epochs' => array_fill_keys($calendar['epochs'], true),
        ], $calendars);
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::INVALID */
    public function check(string $type, string $value): int
    {
        return match ($type) {
            PayloadGrammar::DATE_EXACT => preg_match('/^[0-9]+ [A-Z]{3} [0-9]+$/', $value) === 1 ? $this->date($value) : self::INVALID,
            PayloadGrammar::DATE_PERIOD => $this->period($value) ?? self::INVALID,
            default => $this->period($value) ?? $this->dateValue($value),
        };
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::INVALID|null null when the value is not a period */
    private function period(string $value): ?int
    {
        if ($value === '') {
            return self::VALID;
        }
        if (preg_match('/^(?:FROM (.+?)(?: TO (.+))?|TO (.+))$/', $value, $m) !== 1) {
            return null;
        }

        return $this->dates(array_slice($m, 1));
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::INVALID */
    private function dateValue(string $value): int
    {
        if (preg_match('/^BET (.+) AND (.+)$/', $value, $m) === 1 || preg_match('/^(?:AFT|BEF|ABT|CAL|EST) (.+)$/', $value, $m) === 1) {
            return $this->dates(array_slice($m, 1));
        }

        return $this->date($value);
    }

    /**
     * @param list<string> $dates empty strings are absent groups
     *
     * @return self::VALID|self::DAY_OUT_OF_RANGE|self::INVALID
     */
    private function dates(array $dates): int
    {
        $result = self::VALID;
        foreach ($dates as $date) {
            if ($date !== '') {
                $result = max($result, $this->date($date));
                if ($result === self::INVALID) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /** @return self::VALID|self::DAY_OUT_OF_RANGE|self::INVALID */
    private function date(string $date): int
    {
        if (preg_match(self::DATE, $date, $m) !== 1) {
            return self::INVALID;
        }
        $calendar = $m[1] === '' ? 'GREGORIAN' : $m[1];
        $day = $m[2];
        $month = $m[3];
        $epoch = $m[5] ?? '';
        if ($day !== '' && ((int) $day < 1 || (int) $day > self::MAX_DAY)) {
            return self::INVALID;
        }

        $known = $this->calendars[$calendar] ?? null;
        if ($known === null) {
            // An extension calendar: months must be extension tags, epochs are its own.
            return $month === '' || $month[0] === '_' ? self::VALID : self::INVALID;
        }
        // Extension months and epochs may be documented by a standard calendar; they are not checked.
        if (($month !== '' && $month[0] !== '_' && !isset($known['months'][$month]))
            || ($epoch !== '' && $epoch[0] !== '_' && !isset($known['epochs'][$epoch]))) {
            return self::INVALID;
        }
        if ($day === '' || $month[0] === '_') {
            return self::VALID;
        }

        return (int) $day <= self::monthDays($calendar, $month, (int) $m[4], $epoch === 'BCE') ? self::VALID : self::DAY_OUT_OF_RANGE;
    }

    /** Length of a month of GREGORIAN, JULIAN, HEBREW or FRENCH_R; also used for 5.x dates. */
    public static function monthDays(string $calendar, string $month, int $year, bool $bce): int
    {
        $days = self::MONTH_DAYS[$calendar === 'JULIAN' ? 'GREGORIAN' : $calendar][$month] ?? self::MAX_DAY;
        if ($month !== 'FEB' || ($calendar !== 'GREGORIAN' && $calendar !== 'JULIAN')) {
            return $days;
        }
        // There is no year 0: 1 BCE is the astronomical year 0.
        $year = $bce ? 1 - $year : $year;
        $leap = $calendar === 'JULIAN' ? $year % 4 === 0 : $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);

        return $leap ? 29 : 28;
    }
}

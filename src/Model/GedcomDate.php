<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Validation\Rule\DateGrammar;
use Korin\Gedcom\Version;

/**
 * A date value: a kind (a date, a range, a period, an approximation or none), its dates and a
 * phrase. The same value object for every version: {@see self::parse()} reads the grammar of a
 * version, {@see self::toGedcom()} writes it. In 7.0 the phrase is not part of the value but the
 * PHRASE substructure; in 5.x it is "INT date (phrase)" or "(phrase)".
 *
 * Only what the validator finds valid without a warning is parsed: no day beyond its month, no
 * dual year that is not the following year.
 */
final readonly class GedcomDate implements \Stringable
{
    private const CALENDARS_5 = ['GREGORIAN' => Date::GREGORIAN, 'JULIAN' => Date::JULIAN, 'HEBREW' => Date::HEBREW, 'FRENCH R' => Date::FRENCH_R, 'ROMAN' => Date::ROMAN, 'UNKNOWN' => Date::UNKNOWN];

    private const DATE_5 = '/^(?:@#D([A-Z ]+)@ )?(?:([0-9]{1,2}) (?!BCE?$)([A-Z]+)$|(?:(?:([0-9]+) )?([A-Z][A-Z0-9_]*) )?([0-9]+)(?:\/([0-9]{2}))?( ?(?:BCE|BC|B\.C\.))?$)/';

    public function __construct(
        public DateKind $kind,
        public ?Date $date = null,
        public ?Date $endDate = null,
        public ?string $phrase = null,
    ) {
        $count = ($date === null ? 0 : 1) + ($endDate === null ? 0 : 1);
        if ($count !== $kind->dates() || ($date === null && $endDate !== null) || $phrase === '') {
            throw new InvalidValueException("A {$kind->name} date has {$kind->dates()} date(s); a phrase is not empty");
        }
    }

    /** @throws InvalidValueException when the value is not a valid date of the version */
    public static function parse(string $value, Version $version = Version::V7_0): self
    {
        return self::tryParse($value, $version) ?? throw new InvalidValueException("Not a valid GEDCOM {$version->value} date");
    }

    public static function tryParse(string $value, Version $version = Version::V7_0): ?self
    {
        if (!Grammars::isDate($version, $value)) {
            return null;
        }
        try {
            return $version === Version::V7_0 ? self::parse7($value) : self::parse5($value);
        } catch (InvalidValueException) {
            // The 5.x calendars ROMAN and UNKNOWN take any day and dual year ("@#DROMAN@ 0 IUL 753").
            return null;
        }
    }

    /** "6 DEC 1907", "JULIAN 1650": one date. */
    public static function of(Date $date): self
    {
        return new self(DateKind::Date, $date);
    }

    public function withPhrase(?string $phrase): self
    {
        return new self($this->kind, $this->date, $this->endDate, $phrase === '' ? null : $phrase);
    }

    /**
     * The value as the version writes it; a 7.0 value leaves the phrase to PHRASE.
     *
     * @throws InvalidValueException when the version cannot write it: a dual year or a 5.x
     *         calendar in 7.0; a 7.0 extension, or a phrase with anything but one date or none, in 5.x
     */
    public function toGedcom(Version $version = Version::V7_0): string
    {
        $value = $version === Version::V7_0 ? $this->format($this->date7(...)) : $this->format5($version === Version::V5_5_5);
        if (!Grammars::isDate($version, $value)) {
            throw new InvalidValueException("The date cannot be written in GEDCOM {$version->value}");
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->toGedcom();
    }

    /**
     * The days the value can mean, as Julian Day Numbers, PHP_INT_MIN / PHP_INT_MAX for an open
     * end: "BEF 1907" ends on 31 DEC 1907, "BET 1900 AND 1910" spans both years. Null when they
     * are not certain: approximations, no date, other calendars than Gregorian and Julian.
     *
     * @return ?array{int, int}
     */
    public function days(): ?array
    {
        $first = $this->date?->days();
        if ($first === null || in_array($this->kind, [DateKind::About, DateKind::Calculated, DateKind::Estimated], true)) {
            return null;
        }

        return match ($this->kind) {
            DateKind::Before, DateKind::To => [PHP_INT_MIN, $first[1]],
            DateKind::After, DateKind::From => [$first[0], PHP_INT_MAX],
            DateKind::Between, DateKind::Period => ($last = $this->endDate?->days()) === null ? null : [$first[0], $last[1]],
            default => $first,
        };
    }

    private static function parse7(string $value): self
    {
        [$kind, $dates] = self::split($value);

        return new self($kind, ...array_map(self::date7FromString(...), $dates));
    }

    private static function parse5(string $value): self
    {
        if (preg_match('/^\((.*)\)$/s', $value, $m) === 1) {
            return new self(DateKind::None, phrase: $m[1]);
        }
        if (preg_match('/^INT (.+?) \((.*)\)$/si', $value, $m) === 1) {
            return new self(DateKind::Date, self::date5FromString(strtoupper($m[1])), phrase: $m[2] === '' ? null : $m[2]);
        }
        [$kind, $dates] = self::split(strtoupper($value));

        return new self($kind, ...array_map(self::date5FromString(...), $dates));
    }

    /** @return array{DateKind, list<string>} */
    private static function split(string $value): array
    {
        return match (true) {
            $value === '' => [DateKind::None, []],
            preg_match('/^FROM (.+?) TO (.+)$/', $value, $m) === 1 => [DateKind::Period, [$m[1], $m[2]]],
            preg_match('/^BET (.+?) AND (.+)$/', $value, $m) === 1 => [DateKind::Between, [$m[1], $m[2]]],
            preg_match('/^(FROM|TO|BEF|AFT|ABT|CAL|EST) (.+)$/', $value, $m) === 1 => [DateKind::from($m[1]), [$m[2]]],
            default => [DateKind::Date, [$value]],
        };
    }

    private static function date7FromString(string $date): Date
    {
        if (preg_match(DateGrammar::DATE, $date, $m) !== 1) {
            throw new InvalidValueException('Not a 7.0 date');
        }

        return new Date((int) $m[4], $m[3] === '' ? null : $m[3], $m[2] === '' ? null : (int) $m[2], $m[1] === '' ? Date::GREGORIAN : $m[1], ($m[5] ?? '') === '' ? null : $m[5]);
    }

    private static function date5FromString(string $date): Date
    {
        if (preg_match(self::DATE_5, $date, $m) !== 1) {
            throw new InvalidValueException('Not a 5.x date');
        }
        // Groups of the other alternative, and trailing ones, are absent.
        $m = array_pad($m, 9, '');
        $calendar = self::CALENDARS_5[$m[1]] ?? Date::GREGORIAN;
        if ($m[2] !== '') {
            // 5.5.5: a day and month without a year.
            return new Date(null, $m[3], (int) $m[2], $calendar);
        }
        $year = (int) $m[6];
        $dual = null;
        if ($m[7] !== '') {
            $dual = $year - $year % 100 + (int) $m[7];
            $dual = $dual < $year ? $dual + 100 : $dual;
        }

        return new Date($year, $m[5] === '' ? null : $m[5], $m[4] === '' ? null : (int) $m[4], $calendar, $m[8] === '' ? null : Date::BCE, $dual);
    }

    /** @param \Closure(Date): string $date */
    private function format(\Closure $date): string
    {
        // The constructor makes sure the kind has its dates.
        $first = $this->date === null ? '' : $date($this->date);
        $second = $this->endDate === null ? '' : $date($this->endDate);

        return match ($this->kind) {
            DateKind::None => '',
            DateKind::Date => $first,
            DateKind::Between => "BET {$first} AND {$second}",
            DateKind::Period => "FROM {$first} TO {$second}",
            default => "{$this->kind->value} {$first}",
        };
    }

    private function format5(bool $v555): string
    {
        $value = $this->format(static fn(Date $date): string => self::date5($date, $v555));

        return match (true) {
            $this->phrase === null => $value,
            $this->kind === DateKind::None => "({$this->phrase})",
            $this->kind === DateKind::Date => "INT {$value} ({$this->phrase})",
            default => throw new InvalidValueException('GEDCOM 5.x has a phrase only with one date or none'),
        };
    }

    private function date7(Date $date): string
    {
        if ($date->dualYear !== null || $date->calendar === Date::ROMAN || $date->calendar === Date::UNKNOWN) {
            throw new InvalidValueException('GEDCOM 7.0 has neither dual years nor the 5.x calendars ROMAN and UNKNOWN');
        }

        return ($date->calendar === Date::GREGORIAN ? '' : $date->calendar . ' ') . self::parts($date) . ($date->epoch === null ? '' : ' ' . $date->epoch);
    }

    /** @param bool $v555 5.5.5 years have at least 3 digits: "050" */
    private static function date5(Date $date, bool $v555): string
    {
        $escape = array_search($date->calendar, self::CALENDARS_5, true);
        if ($escape === false || ($date->epoch !== null && !$date->isBce()) || str_starts_with((string) $date->month, '_')) {
            throw new InvalidValueException('GEDCOM 5.x has no extension calendars, months or epochs');
        }
        $dual = $date->dualYear === null ? '' : '/' . sprintf('%02d', $date->dualYear % 100);

        return ($escape === 'GREGORIAN' ? '' : "@#D{$escape}@ ") . self::parts($date, $v555 ? 3 : 1) . $dual . ($date->isBce() ? ' B.C.' : '');
    }

    private static function parts(Date $date, int $yearDigits = 1): string
    {
        $year = $date->year === null ? '' : sprintf("%0{$yearDigits}d", $date->year);

        return trim(($date->day === null ? '' : $date->day . ' ') . ($date->month === null ? '' : $date->month . ' ') . $year);
    }
}

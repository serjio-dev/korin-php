<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Model\Date;
use Korin\Gedcom\Spec\JulianDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): Date}> */
    public static function invalid(): iterable
    {
        yield 'negative year' => [static fn(): Date => new Date(-1)];
        yield 'year above the maximum' => [static fn(): Date => new Date(Date::MAX_YEAR + 1)];
        yield 'day without a month' => [static fn(): Date => new Date(1900, day: 1)];
        yield 'day 0' => [static fn(): Date => new Date(1900, 'JAN', 0)];
        yield 'day without a year' => [static fn(): Date => new Date(null)];
        yield 'month in lowercase' => [static fn(): Date => new Date(1900, 'jan')];
        yield 'text before a month' => [static fn(): Date => new Date(1900, 'x JAN')];
        yield 'text after a month' => [static fn(): Date => new Date(1900, 'JAN x')];
        yield 'text before a calendar' => [static fn(): Date => new Date(1900, calendar: 'x JULIAN')];
        yield 'text after a calendar' => [static fn(): Date => new Date(1900, calendar: 'JULIAN x')];
        yield 'text before an epoch' => [static fn(): Date => new Date(1900, epoch: 'x BCE')];
        yield 'text after an epoch' => [static fn(): Date => new Date(1900, epoch: 'BCE x')];
        yield 'dual year without a year' => [static fn(): Date => new Date(null, 'JAN', 1, dualYear: 1)];
        yield 'dual year not after the year' => [static fn(): Date => new Date(1900, dualYear: 1900)];
        yield 'dual year 100 after the year' => [static fn(): Date => new Date(1900, dualYear: 2000)];
    }

    /** @param \Closure(): Date $make */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidDatesAreRejected(\Closure $make): void
    {
        $this->expectException(InvalidValueException::class);

        $make();
    }

    #[Test]
    public function limitsOfAValidDate(): void
    {
        self::assertSame(0, (new Date(0))->year);
        self::assertSame(Date::MAX_YEAR, (new Date(Date::MAX_YEAR))->year);
        self::assertSame(1999, (new Date(1900, dualYear: 1999))->dualYear);
        self::assertSame('_X', (new Date(1, '_X', calendar: '_C', epoch: '_E'))->month);
    }

    #[Test]
    public function yearsKeepLeadingZerosOutOfTheirLength(): void
    {
        self::assertSame(1907, Date::year('0001907'));
        self::assertSame(0, Date::year('000'));
        self::assertSame(Date::MAX_YEAR, Date::year('000' . Date::MAX_YEAR));

        $this->expectExceptionObject(new InvalidValueException('A year of more than ' . strlen((string) Date::MAX_YEAR) . ' digits'));
        Date::year('1' . Date::MAX_YEAR);
    }

    #[Test]
    public function daysOnlyForGregorianAndJulianDatesWithAStandardMonth(): void
    {
        self::assertSame([JulianDays::day(0, 1, 1, false), JulianDays::day(0, 12, 31, false)], (new Date(1, epoch: Date::BCE))->days(), '1 BCE is year 0');
        self::assertSame([JulianDays::day(-1, 3, 1, true), JulianDays::day(-1, 3, 31, true)], (new Date(2, 'MAR', calendar: Date::JULIAN, epoch: Date::BCE))->days());
        self::assertNull((new Date(1900, '_X'))->days());
        self::assertNull((new Date(1900, epoch: '_E'))->days());
        self::assertNull((new Date(1900, calendar: Date::HEBREW))->days());
        self::assertNull((new Date(null, 'MAR', 1))->days());
    }
}

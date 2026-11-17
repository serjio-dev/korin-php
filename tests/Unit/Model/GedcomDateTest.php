<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Model\Date;
use Korin\Gedcom\Model\DateKind;
use Korin\Gedcom\Model\GedcomDate;
use Korin\Gedcom\Spec\JulianDays;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GedcomDateTest extends TestCase
{
    /** @return iterable<string, array{string, Version, GedcomDate, string}> value, its version, the date, the value in 7.0 or "!" */
    public static function dates(): iterable
    {
        yield 'exact' => ['6 DEC 1907', Version::V7_0, GedcomDate::of(Date::of(1907, 'DEC', 6)), '6 DEC 1907'];
        yield 'year' => ['1907', Version::V5_5_1, GedcomDate::of(Date::of(1907)), '1907'];
        yield 'about, Julian' => ['ABT JULIAN 1650', Version::V7_0, new GedcomDate(DateKind::About, Date::of(1650, calendar: Date::JULIAN)), 'ABT JULIAN 1650'];
        yield '5.5.1 escape, lowercase' => ['abt @#DJULIAN@ 1650', Version::V5_5_1, new GedcomDate(DateKind::About, Date::of(1650, calendar: Date::JULIAN)), 'ABT JULIAN 1650'];
        yield 'between' => ['BET 1900 AND MAR 1910', Version::V7_0, new GedcomDate(DateKind::Between, Date::of(1900), Date::of(1910, 'MAR')), 'BET 1900 AND MAR 1910'];
        yield 'period' => ['FROM 1900 TO 1910', Version::V5_5, new GedcomDate(DateKind::Period, Date::of(1900), Date::of(1910)), 'FROM 1900 TO 1910'];
        yield 'to' => ['TO 1850', Version::V7_0, new GedcomDate(DateKind::To, Date::of(1850)), 'TO 1850'];
        yield 'French republican' => ['@#DFRENCH R@ 1 VEND 12', Version::V5_5_1, GedcomDate::of(Date::of(12, 'VEND', 1, Date::FRENCH_R)), 'FRENCH_R 1 VEND 12'];
        yield 'Hebrew, 7.0' => ['HEBREW 1 TSH 5700', Version::V7_0, GedcomDate::of(Date::of(5700, 'TSH', 1, Date::HEBREW)), 'HEBREW 1 TSH 5700'];
        yield 'BCE' => ['44 BCE', Version::V7_0, GedcomDate::of(new Date(44, epoch: Date::BCE)), '44 BCE'];
        yield 'B.C.' => ['44 B.C.', Version::V5_5_1, GedcomDate::of(new Date(44, epoch: Date::BCE)), '44 BCE'];
        yield 'dual year' => ['30 JAN 1648/49', Version::V5_5_1, GedcomDate::of(new Date(1648, 'JAN', 30, dualYear: 1649)), '!'];
        yield 'dual year over a century' => ['1699/00', Version::V5_5_1, GedcomDate::of(new Date(1699, dualYear: 1700)), '!'];
        yield 'interpreted' => ['INT 1907 (about Christmas)', Version::V5_5_1, GedcomDate::of(Date::of(1907))->withPhrase('about Christmas'), '1907'];
        yield 'phrase' => ['(spring)', Version::V5_5_1, new GedcomDate(DateKind::None, phrase: 'spring'), ''];
        yield 'empty' => ['', Version::V7_0, new GedcomDate(DateKind::None), ''];
        yield 'Roman' => ['@#DROMAN@ 24 IUL 753', Version::V5_5_1, GedcomDate::of(Date::of(753, 'IUL', 24, Date::ROMAN)), '!'];
        yield '5.5.5 day and month' => ['23 AUG', Version::V5_5_5, GedcomDate::of(Date::of(null, 'AUG', 23)), '!'];
        yield 'extension calendar' => ['_MAYA _BAKTUN 13 _LC', Version::V7_0, GedcomDate::of(new Date(13, '_BAKTUN', calendar: '_MAYA', epoch: '_LC')), '_MAYA _BAKTUN 13 _LC'];
    }

    #[Test]
    #[DataProvider('dates')]
    public function datesAreParsedAndWritten(string $value, Version $version, GedcomDate $expected, string $in70): void
    {
        $date = GedcomDate::parse($value, $version);

        self::assertEquals($expected, $date);
        self::assertEquals($date, GedcomDate::parse($date->toGedcom($version), $version));
        if ($in70 === '!') {
            $this->expectException(InvalidValueException::class);
        }
        self::assertSame($in70, $date->toGedcom(Version::V7_0));
    }

    /** @return iterable<string, array{string, Version}> */
    public static function invalid(): iterable
    {
        yield 'day beyond the month' => ['31 NOV 1900', Version::V7_0];
        yield 'unknown month' => ['1 FOO 1900', Version::V7_0];
        yield '5.5.1 escape in 7.0' => ['@#DJULIAN@ 1650', Version::V7_0];
        yield '7.0 calendar in 5.5.1' => ['JULIAN 1650', Version::V5_5_1];
        yield 'lowercase in 7.0' => ['abt 1900', Version::V7_0];
        yield 'dual year not the next one' => ['1701/99', Version::V5_5_1];
        yield 'text' => ['spring 1900', Version::V5_5_1];
        yield '7.0 INT' => ['INT 1900 (x)', Version::V7_0];
        yield 'Roman day 0, in the grammar' => ['@#DROMAN@ 0 IUL 753', Version::V5_5_1];
        yield 'unknown calendar, dual year not after the year' => ['@#DUNKNOWN@ 1648/48', Version::V5_5_1];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function invalidDatesAreNotParsed(string $value, Version $version): void
    {
        self::assertNull(GedcomDate::tryParse($value, $version));
        $this->expectException(InvalidValueException::class);
        GedcomDate::parse($value, $version);
    }

    #[Test]
    public function datesAreWrittenForEachVersion(): void
    {
        $date = GedcomDate::parse('ABT JULIAN 50 BCE');

        self::assertSame('ABT @#DJULIAN@ 50 B.C.', $date->toGedcom(Version::V5_5_1));
        self::assertSame('ABT @#DJULIAN@ 050 B.C.', $date->toGedcom(Version::V5_5_5));
        self::assertSame('INT 6 DEC 1907 (St. Nicholas)', GedcomDate::parse('6 DEC 1907')->withPhrase('St. Nicholas')->toGedcom(Version::V5_5));
        self::assertSame('(unknown)', (new GedcomDate(DateKind::None, phrase: 'unknown'))->toGedcom(Version::V5_5_1));
        self::assertSame('BET 1900 AND 1910', (string) GedcomDate::parse('BET 1900 AND 1910', Version::V5_5_1));
    }

    /** @return iterable<string, array{GedcomDate, Version}> */
    public static function unwritable(): iterable
    {
        yield 'a phrase with a range in 5.x' => [GedcomDate::parse('BEF 1900')->withPhrase('x'), Version::V5_5_1];
        yield 'an extension calendar in 5.x' => [GedcomDate::parse('_MAYA 13'), Version::V5_5_1];
        yield 'an extension month in 5.x' => [GedcomDate::parse('JULIAN 1 _X 1650'), Version::V5_5_1];
        yield 'the 5.x Roman calendar in 7.0' => [GedcomDate::parse('@#DROMAN@ 753', Version::V5_5_1), Version::V7_0];
        yield 'a day the month does not have' => [GedcomDate::of(Date::of(1900, 'FEB', 30)), Version::V7_0];
        yield 'BCE with a month in 5.5.5' => [GedcomDate::parse('MAR 1000 BCE'), Version::V5_5_5];
    }

    #[Test]
    #[DataProvider('unwritable')]
    public function datesAVersionCannotWriteThrow(GedcomDate $date, Version $version): void
    {
        $this->expectException(InvalidValueException::class);

        $date->toGedcom($version);
    }

    #[Test]
    public function daysAreThoseTheDateCanMean(): void
    {
        $first = JulianDays::day(1907, 1, 1, false);

        self::assertSame([$first, $first + 364], GedcomDate::parse('1907')->days());
        self::assertSame([$first + 339, $first + 339], GedcomDate::parse('6 DEC 1907')->days());
        self::assertSame([PHP_INT_MIN, $first + 364], GedcomDate::parse('BEF 1907')->days());
        self::assertSame([$first, PHP_INT_MAX], GedcomDate::parse('FROM 1907')->days());
        self::assertSame([$first, JulianDays::day(1909, 12, 31, false)], GedcomDate::parse('BET 1907 AND 1909')->days());
        self::assertSame([$first + 13, $first + 13], GedcomDate::parse('JULIAN 1 JAN 1907')->days(), 'Julian is 13 days behind');
        self::assertSame(GedcomDate::parse('1649')->days(), GedcomDate::parse('1648/49', Version::V5_5_1)->days());
        self::assertNull(GedcomDate::parse('ABT 1907')->days());
        self::assertNull(GedcomDate::parse('HEBREW 5700')->days());
        self::assertNull(GedcomDate::parse('')->days());
        self::assertNull(GedcomDate::parse('99999999999999999999')->days(), 'Years beyond 9999 are not compared');
        self::assertNull(GedcomDate::parse('5000 BCE')->days());
    }

    #[Test]
    public function inconsistentDatesAreRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new GedcomDate(DateKind::Between, Date::of(1900));
    }
}

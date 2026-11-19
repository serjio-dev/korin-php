<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Validation\Rule\DateGrammar;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateGrammarTest extends TestCase
{
    private const VALID = DateGrammar::VALID;
    private const INVALID = DateGrammar::INVALID;
    private const DAY = DateGrammar::DAY_OUT_OF_RANGE;

    #[Test]
    public function datesAreReportedWithoutQuotingThem(): void
    {
        $doc = Gedcom::parse(
            "0 HEAD\n1 GEDC\n2 VERS 7.0\n1 DATE 15 NOV 2026\n0 @I1@ INDI\n1 BIRT\n2 DATE about 1850\n1 DEAT\n2 DATE 31 NOV 1901\n"
            . "1 CHAN\n2 DATE ABT 2026\n1 RESI Kyiv\n2 DATE FROM 1900 TO 1910\n0 @S1@ SOUR\n1 DATA\n2 EVEN BIRT\n3 DATE 1900\n0 TRLR\n",
        );

        self::assertSame([
            'KV3005 error line 7 (@I1@/BIRT/DATE): DATE must be a date, e.g. "6 DEC 1907", "ABT JULIAN 1650" or "BET 1900 AND 1910"',
            'KV3008 warning line 9 (@I1@/DEAT/DATE): DATE has a day beyond the length of its month',
            'KV3005 error line 11 (@I1@/CHAN/DATE): DATE must be an exact Gregorian date, e.g. "6 DEC 1907"',
            'KV3005 error line 17 (@S1@/DATA/EVEN/DATE): DATE must be a date period, e.g. "FROM 1900 TO 1910" or "TO 1850"',
        ], array_map(static fn(Diagnostic $d): string => (string) $d, Gedcom::validate($doc)->diagnostics()));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function dates(): iterable
    {
        $value = PayloadGrammar::DATE;
        yield 'year' => [$value, '1850', self::VALID];
        yield 'full date' => [$value, '6 DEC 1907', self::VALID];
        yield 'empty' => [$value, '', self::VALID];
        yield 'epoch' => [$value, 'MAR 44 BCE', self::VALID];
        yield 'calendar' => [$value, 'JULIAN 23 NOV 1907', self::VALID];
        yield 'approximate' => [$value, 'EST HEBREW ADS 5600', self::VALID];
        yield 'range' => [$value, 'BET FRENCH_R 1 VEND 1 AND GREGORIAN 1800', self::VALID];
        yield 'before' => [$value, 'BEF 1 JAN 1900', self::VALID];
        yield 'period in a date value' => [$value, 'FROM 1670 TO JULIAN 1800', self::VALID];
        yield 'extension calendar' => [$value, '_ROMAN 18 _IDES 709', self::VALID];
        yield 'extension epoch' => [$value, '1200 _AH', self::VALID];
        // Its months are not known: they are not checked (specification 1, "Extensions").
        yield 'extension calendar with standard month' => [$value, '_ROMAN MAR 709', self::VALID];
        yield 'month of another calendar' => [$value, '1 TSH 1900', self::INVALID];
        yield 'epoch not in calendar' => [$value, 'HEBREW 100 BCE', self::INVALID];
        yield 'lower-case month' => [$value, '6 Dec 1907', self::INVALID];
        yield 'no year' => [$value, '6 DEC', self::INVALID];
        yield 'a year longer than an int' => [$value, '29 FEB 99999999999999999996', self::VALID];
        yield 'dual year' => [$value, '30 JAN 1648/49', self::INVALID];
        yield 'phrase' => [$value, '(spring)', self::INVALID];
        yield 'two spaces' => [$value, '6  DEC 1907', self::INVALID];
        yield 'BET without AND' => [$value, 'BET 1900', self::INVALID];
        yield 'day zero' => [$value, '0 JAN 1900', self::INVALID];
        yield 'day over 36' => [$value, '37 JAN 1900', self::INVALID];
        yield 'day without month' => [$value, '6 1907', self::INVALID];
        yield '31 November' => [$value, '31 NOV 1901', self::DAY];
        yield '29 February 1900' => [$value, '29 FEB 1900', self::DAY];
        yield '29 February 2000' => [$value, '29 FEB 2000', self::VALID];
        yield 'Julian 29 February 1900' => [$value, 'JULIAN 29 FEB 1900', self::VALID];
        yield '29 February 1 BCE' => [$value, '29 FEB 1 BCE', self::VALID];
        yield '29 February 2 BCE' => [$value, 'JULIAN 29 FEB 2 BCE', self::DAY];
        yield 'day 36' => [$value, '_X 36 _M 12', self::VALID];
        yield 'day 1 of a standard month' => [$value, '1 JAN 1900', self::VALID];
        yield '29 February 1600, a century leap year' => [$value, '29 FEB 1600', self::VALID];
        yield '29 February 1600 of a year of five digits' => [$value, '29 FEB 11600', self::VALID];
        yield 'Julian 29 February 1905' => [$value, 'JULIAN 29 FEB 1905', self::DAY];
        yield 'Julian 29 February 1904' => [$value, 'JULIAN 29 FEB 1904', self::VALID];
        yield '30 February 2000' => [$value, '30 FEB 2000', self::DAY];
        yield 'Hebrew 30 February-like month' => [$value, 'HEBREW 29 SHV 5600', self::VALID];
        yield 'text before BET' => [$value, 'XBET 1900 AND 1910', self::INVALID];
        yield 'text before AFT' => [$value, 'XAFT 1900', self::INVALID];
        yield 'BET with a bad first date' => [$value, 'BET 31 NOV 1900 AND 1901', self::DAY];
        yield 'ABT with a bad date' => [$value, 'ABT 31 NOV 1900', self::DAY];
        yield 'complementary days' => [$value, 'FRENCH_R 6 COMP 11', self::VALID];
        yield 'too many complementary days' => [$value, 'FRENCH_R 7 COMP 11', self::DAY];
        yield 'Hebrew 30 Tevet' => [$value, 'HEBREW 30 TVT 5600', self::DAY];
        yield 'out of range and invalid' => [$value, 'BET 31 NOV 1900 AND 1 XYZ 1901', self::INVALID];
        yield 'extension calendar day' => [$value, '_MARS 40 _M 12', self::INVALID];

        $period = PayloadGrammar::DATE_PERIOD;
        yield 'period' => [$period, 'FROM 1900 TO 1910', self::VALID];
        yield 'period start' => [$period, 'FROM JULIAN 1 JAN 1700', self::VALID];
        yield 'period end' => [$period, 'TO 1850', self::VALID];
        yield 'empty period' => [$period, '', self::VALID];
        yield 'period bound day' => [$period, 'TO 30 FEB 1850', self::DAY];
        yield 'single date' => [$period, '1900', self::INVALID];
        yield 'text before FROM' => [$period, 'XFROM 1900', self::INVALID];
        yield 'period with a bad start' => [$period, 'FROM 31 NOV 1900 TO 1910', self::DAY];
        yield 'reversed period' => [$period, 'TO 1910 FROM 1900', self::INVALID];

        $exact = PayloadGrammar::DATE_EXACT;
        yield 'exact' => [$exact, '15 NOV 2026', self::VALID];
        yield 'exact with calendar' => [$exact, 'GREGORIAN 15 NOV 2026', self::INVALID];
        yield 'exact epoch' => [$exact, '15 NOV 2026 BCE', self::INVALID];
        yield 'exact without day' => [$exact, 'NOV 2026', self::INVALID];
        yield 'exact other calendar month' => [$exact, '1 TSH 2026', self::INVALID];
        yield 'exact day' => [$exact, '31 APR 2026', self::DAY];
    }

    #[Test]
    #[DataProvider('dates')]
    public function grammar(string $type, string $value, int $expected): void
    {
        self::assertSame($expected, (new DateGrammar(Profile::for(Version::V7_0)->calendars()))->check($type, $value));
    }

    /** @return iterable<string, array{string, int}> */
    public static function aliases(): iterable
    {
        yield 'calendar alias with its month' => ['_CALENDRIER 4 COMP 8', self::VALID];
        yield 'calendar alias, day beyond its month' => ['_CALENDRIER 7 COMP 8', self::DAY];
        yield 'calendar alias with a month of another calendar' => ['_CALENDRIER 4 JAN 8', self::INVALID];
        yield 'month alias in its calendar' => ['FRENCH_R 2 _JOUR 8', self::VALID];
        yield 'month alias in another calendar' => ['2 _JOUR 1900', self::INVALID];
        yield 'both in a range' => ['BET FRENCH_R 2 _JOUR 8 AND _CALENDRIER 4 COMP 8', self::VALID];
    }

    /** Calendars and months that HEAD.SCHMA defines as standard ones (maximal70.ged of gedcom.io). */
    #[Test]
    #[DataProvider('aliases')]
    public function documentedExtensionTagsAreTheirStandardTerms(string $value, int $expected): void
    {
        $grammar = new DateGrammar(Profile::for(Version::V7_0)->calendars(), ['_CALENDRIER' => 'FRENCH_R'], ['_JOUR' => 'COMP']);

        self::assertSame($expected, $grammar->check('https://gedcom.io/terms/v7/type-Date', $value));
    }
}

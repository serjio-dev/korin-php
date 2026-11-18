<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Validation\Rule\DateGrammar5;
use Korin\Gedcom\Validation\Rule\Grammar5;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Grammar5Test extends TestCase
{
    private const HEAD = "0 HEAD\n1 SOUR KORIN\n1 SUBM @U1@\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @U1@ SUBM\n1 NAME Анна\n";

    #[Test]
    public function payloadsAreCheckedAgainstThePrimitiveElements(): void
    {
        $doc = Gedcom::parse(
            self::HEAD . "0 @I1@ INDI\n1 NAME Анна /Коваль/ /Іванівна/\n1 SEX female\n1 BIRT\n2 DATE 30 FEB 1900\n2 AGE 0\n"
            . "1 DEAT Y\n2 DATE ABT 1950\n1 EVEN Appointed chairperson\n2 DATE 1699/01\n1 RESI\n2 DATE Spring 1901\n"
            . "1 FAMC @F1@\n2 PEDI BIRTH\n1 SOUR @S1@\n2 QUAY 4\n2 EVEN BIRT\n3 ROLE (godmother)\n1 NOTE\n"
            . "1 OBJE\n2 FILE photo.png\n3 FORM png\n1 SUBM text\n0 @F1@ FAM\n1 CHIL @I1@\n1 NCHI two\n"
            . "0 @S1@ SOUR\n1 DATA\n2 EVEN BIRT, DEAT\n3 DATE 1900\n1 REPO\n2 CALN 12\n1 CHAN\n2 DATE 15 Nov 2026\n0 TRLR\n",
        );

        self::assertSame([
            'KV3005 error line 11 (@I1@/NAME): NAME must be a personal name (text with at most one /surname/)',
            'KV3006 error line 12 (@I1@/SEX): SEX does not permit "female"; expected one of M, F, U',
            'KV3008 warning line 14 (@I1@/BIRT/DATE): DATE has a day beyond the length of its month',
            'KV3005 error line 15 (@I1@/BIRT/AGE): AGE must be an age, e.g. "35y 3m", "<1y" or "CHILD"',
            'KV3009 warning line 19 (@I1@/EVEN/DATE): DATE has a dual year whose second year does not follow the first',
            'KV3005 error line 21 (@I1@/RESI/DATE): DATE must be a date, e.g. "6 DEC 1907", "ABT @#DJULIAN@ 1650" or "(date phrase)"',
            'KV3006 error line 25 (@I1@/SOUR/QUAY): QUAY does not permit "4"; expected one of 0, 1, 2, 3',
            'KV3006 error line 31 (@I1@/OBJE/FILE/FORM): FORM does not permit "png"; expected one of bmp, gif, jpg, ole, pcx, tif, wav',
            'KV3003 error line 32 (@I1@/SUBM): SUBM must be a pointer to a record',
            'KV3005 error line 35 (@F1@/NCHI): NCHI must be a number',
            'KV3005 error line 39 (@S1@/DATA/EVEN/DATE): DATE must be a date period, e.g. "FROM 1900 TO 1910" or "TO 1850"',
        ], array_map(static fn(Diagnostic $d): string => (string) $d, Gedcom::validate($doc)->diagnostics()));
    }

    #[Test]
    public function longEnumerationsAreNotListed(): void
    {
        $doc = Gedcom::parse(self::HEAD . "1 LANG Klingon\n0 TRLR\n");

        self::assertSame(
            'LANG does not permit "Klingon"; expected one of the 86 values of <LANGUAGE_PREFERENCE>',
            Gedcom::validate($doc)->diagnostics()[0]->message ?? null,
        );
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function dates(): iterable
    {
        $value = 'DATE_VALUE';
        yield 'year' => [$value, '1850', DateGrammar5::VALID];
        yield 'full date' => [$value, '6 DEC 1907', DateGrammar5::VALID];
        yield 'lower case' => [$value, 'abt 6 Dec 1907', DateGrammar5::VALID];
        yield 'julian' => [$value, '@#DJULIAN@ 23 NOV 1907', DateGrammar5::VALID];
        yield 'french' => [$value, '@#DFRENCH R@ 1 VEND 1', DateGrammar5::VALID];
        yield 'hebrew' => [$value, 'BET @#DHEBREW@ ADS 5600 AND @#DGREGORIAN@ 1840', DateGrammar5::VALID];
        yield 'roman' => [$value, '@#DROMAN@ 15 IDES 709', DateGrammar5::VALID];
        yield 'unknown escape' => [$value, '@#DMARTIAN@ 12', DateGrammar5::INVALID];
        yield 'bare calendar name' => [$value, 'JULIAN 1401', DateGrammar5::INVALID];
        yield 'B.C.' => [$value, '44 B.C.', DateGrammar5::VALID];
        yield 'B.C. without space' => [$value, '44B.C.', DateGrammar5::VALID];
        yield 'B.C. after a month' => [$value, 'MAR 44 B.C.', DateGrammar5::VALID];
        yield 'B.C. after a day' => [$value, '15 MAR 44 B.C.', DateGrammar5::VALID];
        yield 'BC without stops' => [$value, 'MAR 44 BC', DateGrammar5::INVALID];
        yield 'dual year' => [$value, '30 JAN 1648/49', DateGrammar5::VALID];
        yield 'dual year over a century' => [$value, '1699/00', DateGrammar5::VALID];
        yield 'dual year not following' => [$value, '1701/99', DateGrammar5::BAD_DUAL_YEAR];
        yield 'one-digit dual year' => [$value, '1751/3', DateGrammar5::INVALID];
        // Found by fuzzing: the year overflowed an int.
        yield 'a year longer than an int' => [$value, '29 FEB 99999999999999999995/96', DateGrammar5::VALID];
        yield 'a long year, not leap' => [$value, '29 FEB 99999999999999999900', DateGrammar5::DAY_OUT_OF_RANGE];
        yield 'dual julian year' => [$value, '@#DJULIAN@ 1648/49', DateGrammar5::INVALID];
        yield 'phrase' => [$value, '(spring, after the flood)', DateGrammar5::VALID];
        yield 'interpreted' => [$value, 'INT 1850 (about the time of the fire)', DateGrammar5::VALID];
        yield 'interpreted without phrase' => [$value, 'INT 1850', DateGrammar5::INVALID];
        yield 'period' => [$value, 'FROM 1900 TO 1910', DateGrammar5::VALID];
        yield 'range' => [$value, 'BET 1900 AND 1910', DateGrammar5::VALID];
        yield 'day and month only' => [$value, '6 DEC', DateGrammar5::INVALID];
        yield 'other calendar month' => [$value, '1 TSH 1900', DateGrammar5::INVALID];
        yield '31 November' => [$value, '31 NOV 1901', DateGrammar5::DAY_OUT_OF_RANGE];
        yield '29 February in a dual leap year' => [$value, '29 FEB 1695/96', DateGrammar5::VALID];
        yield 'out of range and invalid' => [$value, 'BET 31 NOV 1900 AND 1 XYZ 1901', DateGrammar5::INVALID];
        yield 'period alone' => ['DATE_PERIOD', 'FROM JAN 1900', DateGrammar5::VALID];
        yield 'range as a period' => ['DATE_PERIOD', 'BET 1900 AND 1910', DateGrammar5::INVALID];
        yield 'exact' => ['DATE_EXACT', '15 NOV 2026', DateGrammar5::VALID];
        yield 'exact with escape' => ['DATE_EXACT', '@#DGREGORIAN@ 15 NOV 2026', DateGrammar5::INVALID];
        yield 'exact without day' => ['DATE_EXACT', 'NOV 2026', DateGrammar5::INVALID];
    }

    #[Test]
    #[DataProvider('dates')]
    public function dateGrammar(string $grammar, string $value, int $expected): void
    {
        self::assertSame($expected, (new DateGrammar5(Profile::for(Version::V5_5_1)->enumeration(...)))->check($grammar, $value));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function dates555(): iterable
    {
        $value = 'DATE_VALUE';
        yield 'year' => [$value, '1850', DateGrammar5::VALID];
        yield 'padded year' => [$value, '044 BCE', DateGrammar5::VALID];
        yield 'two-digit year' => [$value, '44 BCE', DateGrammar5::INVALID];
        yield 'BC' => [$value, '1066 BC', DateGrammar5::VALID];
        yield 'B.C.' => [$value, '1066 B.C.', DateGrammar5::VALID];
        yield 'BCE without space' => [$value, '1066BCE', DateGrammar5::INVALID];
        yield 'BCE after a month' => [$value, 'MAR 1066 BCE', DateGrammar5::INVALID];
        yield 'BCE in hebrew' => [$value, '@#DHEBREW@ 1066 BCE', DateGrammar5::INVALID];
        yield 'day with leading zero' => [$value, '06 DEC 1907', DateGrammar5::INVALID];
        yield 'day and month' => [$value, '23 AUG', DateGrammar5::VALID];
        yield '29 February without year' => [$value, '29 FEB', DateGrammar5::VALID];
        yield 'julian day and month' => [$value, '@#DJULIAN@ 23 AUG', DateGrammar5::INVALID];
        yield 'dual julian year' => [$value, '@#DJULIAN@ 30 JAN 1648/49', DateGrammar5::VALID];
        yield 'dual year without month' => [$value, '1648/49', DateGrammar5::INVALID];
        yield 'dual year not following' => [$value, 'FEB 1701/99', DateGrammar5::BAD_DUAL_YEAR];
        yield 'roman' => [$value, '@#DROMAN@ 15 IDES 709', DateGrammar5::INVALID];
        yield 'unknown' => [$value, '@#DUNKNOWN@ 15 IDES 709', DateGrammar5::VALID];
        yield '31 November' => [$value, 'ABT 31 NOV 1901', DateGrammar5::DAY_OUT_OF_RANGE];
        yield 'exact' => ['DATE_EXACT', '15 NOV 2026', DateGrammar5::VALID];
        yield 'exact with dual year' => ['DATE_EXACT', '15 FEB 1700/01', DateGrammar5::INVALID];
    }

    #[Test]
    #[DataProvider('dates555')]
    public function dateGrammar555(string $grammar, string $value, int $expected): void
    {
        self::assertSame($expected, (new DateGrammar5(Profile::for(Version::V5_5_5)->enumeration(...), v555: true))->check($grammar, $value));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function values(): iterable
    {
        yield 'age' => ['AGE_AT_EVENT', '>99y 11m 30d', true];
        yield 'age keyword' => ['AGE_AT_EVENT', 'Stillborn', true];
        yield 'age space after bound' => ['AGE_AT_EVENT', '< 1y', false];
        yield 'age without unit' => ['AGE_AT_EVENT', '35', false];
        yield 'age weeks' => ['AGE_AT_EVENT', '8w', false];
        yield 'age order' => ['AGE_AT_EVENT', '3m 1y', false];
        yield 'time' => ['TIME_VALUE', '14:30:15.25', true];
        yield 'time utc' => ['TIME_VALUE', '14:30Z', false];
        yield 'latitude' => ['PLACE_LATITUDE', 'N18.150944', true];
        yield 'longitude' => ['PLACE_LONGITUDE', 'E168.150944', true];
        yield 'longitude degrees' => ['PLACE_LONGITUDE', '30.52', false];
        yield 'number' => ['NUMBER', '12', true];
        yield 'name' => ['NAME_PERSONAL', 'William /Lee/ Parry', true];
        yield 'name two surnames' => ['NAME_PERSONAL', '/Lee/ /Parry/', false];
    }

    #[Test]
    #[DataProvider('values')]
    public function grammars(string $grammar, string $value, bool $valid): void
    {
        self::assertSame($valid, Grammar5::matches($grammar, $value));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function values555(): iterable
    {
        yield 'age bound with space' => ['AGE_AT_EVENT', '< 1y', true];
        yield 'age bound without space' => ['AGE_AT_EVENT', '<1y', false];
        yield 'age zero part' => ['AGE_AT_EVENT', '0y 3m', false];
        yield 'age leading zero' => ['AGE_AT_EVENT', '05y', false];
        yield 'age 12 months' => ['AGE_AT_EVENT', '1y 12m', false];
        yield 'age 365 days' => ['AGE_AT_EVENT', '1y 365d', true];
        yield 'age 366 days' => ['AGE_AT_EVENT', '366d', false];
        yield 'age four-digit years' => ['AGE_AT_EVENT', '1000y', false];
        yield 'time' => ['TIME_VALUE', '9:05:30.25', true];
        yield 'time leading zero' => ['TIME_VALUE', '09:05', false];
        yield 'time one-digit fraction' => ['TIME_VALUE', '9:05:30.2', false];
        yield 'gedcom version' => ['GEDCOM_VERSION_NUMBER', '5.5.5', true];
        yield 'gedcom version leading zero' => ['GEDCOM_VERSION_NUMBER', '5.05', false];
        yield 'gedcom version four parts' => ['GEDCOM_VERSION_NUMBER', '5.5.5.1', false];
        yield 'product version' => ['PRODUCT_VERSION_NUMBER', '10.0.3.64', true];
        yield 'product version alone' => ['PRODUCT_VERSION_NUMBER', '10', false];
        yield 'place' => ['PLACE_NAME', 'Cove, Cache, Utah, United States of America', true];
        yield 'place empty part' => ['PLACE_NAME', 'Cove, , Utah', false];
        yield 'place comma without space' => ['PLACE_NAME', 'Cove,Cache', false];
    }

    #[Test]
    #[DataProvider('values555')]
    public function grammars555(string $grammar, string $value, bool $valid): void
    {
        self::assertSame($valid, Grammar5::matches($grammar, $value, v555: true));
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\Rule\V551ToV70\Ages;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Dates;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Enumerations;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V551ToV70ValuesTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?array{string, ?string}}> */
    public static function dates(): iterable
    {
        yield 'unchanged' => ['DATE_VALUE', 'BET 1900 AND 1910', null];
        yield 'case' => ['DATE_VALUE', 'abt 12 aug 1401', ['ABT 12 AUG 1401', null]];
        yield 'escape' => ['DATE_VALUE', '@#DJULIAN@ 1650', ['JULIAN 1650', null]];
        yield 'french' => ['DATE_VALUE', '@#DFRENCH R@ 27 THER 77', ['FRENCH_R 27 THER 77', null]];
        yield 'roman months are extensions' => ['DATE_VALUE', '@#DROMAN@ 24 IUL 15', ['_ROMAN 24 _IUL 15', null]];
        yield 'bce' => ['DATE_VALUE', 'FROM 44 B.C. TO 30 B.C.', ['FROM 44 BCE TO 30 BCE', null]];
        yield 'bce without space' => ['DATE_VALUE', 'MAR 44B.C.', ['MAR 44 BCE', null]];
        yield 'dual' => ['DATE_VALUE', '30 JAN 1648/49', ['30 JAN 1649', '30 JAN 1648/49']];
        yield 'dual across a century' => ['DATE_VALUE', '1699/00', ['1700', '1699/00']];
        yield 'dual with leading zeros' => ['DATE_VALUE', '0999/00', ['1000', '0999/00']];
        yield 'dual of a year an int does not hold' => ['DATE_VALUE', '8 JAN 99999999999999999999/00', ['8 JAN 100000000000000000000', '8 JAN 99999999999999999999/00']];
        yield 'dual not the next year' => ['DATE_VALUE', 'JAN 1701/99', ['JAN 1701', 'JAN 1701/99']];
        yield 'dual in a range' => ['DATE_VALUE', 'BET 1648/49 AND 1650', ['BET 1649 AND 1650', 'BET 1648/49 AND 1650']];
        yield 'interpreted' => ['DATE_VALUE', 'INT 5 MAR 1900 (Fifth of March)', ['5 MAR 1900', 'Fifth of March']];
        yield 'interpreted dual' => ['DATE_VALUE', 'INT 1699/00 (x)', ['1700', 'INT 1699/00 (x)']];
        yield 'phrase' => ['DATE_VALUE', '(Spring, after the flood)', ['', 'Spring, after the flood']];
        yield 'two phrases' => ['DATE_VALUE', '(spring) or (summer)', ['', '(spring) or (summer)']];
        yield 'interpreted with parentheses' => ['DATE_VALUE', 'INT 1900 (a) or (b)', ['1900', 'INT 1900 (a) or (b)']];
        yield 'seven date kept' => ['DATE_VALUE', 'julian 1650', ['JULIAN 1650', null]];
        yield 'not a date' => ['DATE_VALUE', 'around Easter', ['', 'around Easter']];
        yield 'hebrew without bce' => ['DATE_VALUE', '@#DHEBREW@ 5000 B.C.', ['', '@#DHEBREW@ 5000 B.C.']];
        yield 'julian dual' => ['DATE_VALUE', '@#DJULIAN@ 1648/49', ['', '@#DJULIAN@ 1648/49']];
        yield 'unknown escape' => ['DATE_VALUE', '@#DMAYAN@ 1000', ['', '@#DMAYAN@ 1000']];
        yield 'month of another calendar' => ['DATE_VALUE', '@#DHEBREW@ 1 JAN 5000', ['', '@#DHEBREW@ 1 JAN 5000']];
        yield 'period' => ['DATE_PERIOD', 'from 1900 to 1910', ['FROM 1900 TO 1910', null]];
        yield 'period with a dual year' => ['DATE_PERIOD', 'TO 1648/49', ['TO 1649', 'TO 1648/49']];
        yield 'not a period' => ['DATE_PERIOD', 'ABT 1900', ['', 'ABT 1900']];
        yield 'exact' => ['DATE_EXACT', '1 jan 2020', ['1 JAN 2020', null]];
        yield 'exact not convertible' => ['DATE_EXACT', 'yesterday', null];
    }

    /** @param ?array{string, ?string} $expected */
    #[Test]
    #[DataProvider('dates')]
    public function datesFollowTheSevenGrammar(string $grammar, string $value, ?array $expected): void
    {
        self::assertSame($expected, (new Dates())->convert($grammar, $value));
    }

    /** @return iterable<string, array{string, ?array{string, ?string}}> */
    public static function ages(): iterable
    {
        yield 'unchanged' => ['< 1y', null];
        yield 'spaces and case' => ['>0 Y', ['> 0y', null]];
        yield 'order' => ['30d11m 99y', ['99y 11m 30d', null]];
        yield 'number of years' => ['8', ['8y', null]];
        yield 'weeks' => ['2W', ['2w', null]];
        yield 'child' => ['CHILD', ['< 8y', 'CHILD']];
        yield 'infant' => ['Infant', ['< 1y', 'Infant']];
        yield 'unit twice' => ['1y 2y', ['', '1y 2y']];
        yield 'number without unit beside others' => ['1y 2', ['', '1y 2']];
        yield 'text' => ['about forty', ['', 'about forty']];
        yield 'long digits without backtracking' => [str_repeat('1', 40) . 'x', ['', str_repeat('1', 40) . 'x']];
    }

    /** @param ?array{string, ?string} $expected */
    #[Test]
    #[DataProvider('ages')]
    public function agesFollowTheSevenGrammar(string $value, ?array $expected): void
    {
        self::assertSame($expected, (new Ages())->convert($value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function files(): iterable
    {
        yield 'relative' => ['photos/a.jpg', 'photos/a.jpg'];
        yield 'backslashes' => ['a\relative\path', 'a/relative/path'];
        yield 'unix' => ['/unix/absolute', 'file:///unix/absolute'];
        yield 'windows' => ['c:\windows\absolute', 'file:///c:/windows/absolute'];
        yield 'drive with two slashes' => ['C://photos/a.jpg', 'file:///C:/photos/a.jpg'];
        yield 'unc' => ['\\\\server\share\a.jpg', 'file://server/share/a.jpg'];
        yield 'encoded' => ['most/paths?get#escaped[like]this 100%', 'most/paths%3Fget%23escaped%5Blike%5Dthis%20100%25'];
        yield 'percent-encoding' => ['media/Bront%C3%AB.jpg', 'media/Bront%C3%AB.jpg'];
        yield 'percent-encoding of not a url' => ['https%3a//not.a.url', 'https%3a//not.a.url'];
        yield 'not quite a url' => ['https:/not.a.url', 'https%3A/not.a.url'];
        yield 'url' => ['https://example.com/a b.jpg?x=1#y', 'https://example.com/a%20b.jpg?x=1#y'];
        yield 'uri without authority' => ['urn:isbn:123', 'urn:isbn:123'];
        yield 'colon in a later segment' => ['photos/ab:c.jpg', 'photos/ab:c.jpg'];
        yield 'windows file url' => ['file://C:\photos\a b.jpg', 'file:///C:/photos/a%20b.jpg'];
        yield 'file url' => ['file:///home/a.jpg', 'file:///home/a.jpg'];
        yield 'non-ascii' => ['фото/Київ.jpg', 'фото/Київ.jpg'];
    }

    #[Test]
    #[DataProvider('files')]
    public function filesBecomeUrls(string $value, string $expected): void
    {
        self::assertSame($expected, (new Media())->url($value));
    }

    /** @return iterable<string, array{string, string, ?array{string, ?string}}> */
    public static function enumerations(): iterable
    {
        yield 'in the set' => ['<PEDIGREE_LINKAGE_TYPE>', 'birth', ['BIRTH', null]];
        yield 'outside, OTHER' => ['<NAME_TYPE>', 'Screen', ['OTHER', 'Screen']];
        yield 'outside, a citation: EVEN' => ['<EVENT_TYPE_CITED_FROM>', 'Census of Galicia', ['EVEN', 'Census of Galicia']];
        yield 'outside, no OTHER in the set' => ['<ADOPTED_BY_WHICH_PARENT>', 'Grandma', null];
        yield 'outside, no PHRASE' => ['<CERTAINTY_ASSESSMENT>', '7', null];
    }

    /** @param ?array{string, ?string} $expected */
    #[Test]
    #[DataProvider('enumerations')]
    public function enumerationsTakeTheSevenValue(string $element, string $value, ?array $expected): void
    {
        self::assertSame($expected, (new Enumerations())->convert($element, $value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function formats(): iterable
    {
        yield 'known' => ['jpg', 'image/jpeg'];
        yield 'case and dot' => ['.TIF', 'image/tiff'];
        yield 'unknown' => ['other', 'application/x-other'];
        yield 'media type' => ['image/x-portable', 'image/x-portable'];
        yield 'not a token' => ['two words', 'two words'];
    }

    #[Test]
    #[DataProvider('formats')]
    public function formatsBecomeMediaTypes(string $value, string $expected): void
    {
        self::assertSame($expected, (new Media())->mediaType($value));
    }
}

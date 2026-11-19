<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\Rule\V70ToV551\Dates;
use Korin\Gedcom\Conversion\Rule\V70ToV551\Media;
use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V70ToV551ValuesTest extends TestCase
{
    private const HEAD = "\u{FEFF}0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 7.0\n";

    /**
     * 7.0 lines inside an INDI, the same lines in 5.5.1, and the losses.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function values(): iterable
    {
        yield 'date unchanged' => ["1 BIRT\n2 DATE BET 1900 AND 1910\n", "1 BIRT\n2 DATE BET 1900 AND 1910\n", []];
        yield 'calendars' => ["1 BIRT\n2 DATE BET JULIAN 1650 AND GREGORIAN 1660\n", "1 BIRT\n2 DATE BET @#DJULIAN@ 1650 AND @#DGREGORIAN@ 1660\n", []];
        yield 'french' => ["1 BIRT\n2 DATE FRENCH_R 27 THER 7\n", "1 BIRT\n2 DATE @#DFRENCH R@ 27 THER 7\n", []];
        yield 'roman' => ["1 BIRT\n2 DATE _ROMAN 24 _IUL 15\n", "1 BIRT\n2 DATE @#DROMAN@ 24 IUL 15\n", []];
        yield 'bce' => ["1 BIRT\n2 DATE FROM 44 BCE TO 30 BCE\n", "1 BIRT\n2 DATE FROM 44 B.C. TO 30 B.C.\n", []];
        yield 'dual year back' => ["1 BIRT\n2 DATE 30 JAN 1649\n3 PHRASE 30 JAN 1648/49\n", "1 BIRT\n2 DATE 30 JAN 1648/49\n", []];
        yield 'interpreted' => ["1 BIRT\n2 DATE 5 MAR 1900\n3 PHRASE Fifth of March\n", "1 BIRT\n2 DATE INT 5 MAR 1900 (Fifth of March)\n", []];
        yield 'phrase only' => ["1 BIRT\n2 DATE\n3 PHRASE Spring, after the flood\n", "1 BIRT\n2 DATE (Spring, after the flood)\n", []];
        yield 'phrase with parentheses' => ["1 BIRT\n2 DATE\n3 PHRASE (spring) or (summer)\n", "1 BIRT\n2 DATE (spring) or (summer)\n", []];
        yield 'phrase of a range' => ["1 BIRT\n2 DATE ABT 1900\n3 PHRASE about then\n", "1 BIRT\n2 DATE ABT 1900\n3 _PHRASE about then\n", ['KL2001 @I1@/BIRT/DATE/PHRASE extension']];
        yield 'extension calendar' => ["1 BIRT\n2 DATE _MAYAN 1000\n", "1 BIRT\n2 _DATE _MAYAN 1000\n", ['KL3001 @I1@/BIRT/DATE extension']];
        yield 'exact date' => ["1 CHAN\n2 DATE 1 JAN 2020\n3 TIME 10:00\n", "1 CHAN\n2 DATE 1 JAN 2020\n3 TIME 10:00\n", []];
        yield 'age' => ["1 DEAT\n2 AGE > 72y 3m\n", "1 DEAT\n2 AGE >72y 3m\n", []];
        yield 'age word' => ["1 DEAT\n2 AGE < 8y\n3 PHRASE CHILD\n", "1 DEAT\n2 AGE CHILD\n", []];
        yield 'age in weeks' => ["1 DEAT\n2 AGE 2w\n", "1 DEAT\n2 _AGE 2w\n", ['KL3001 @I1@/DEAT/AGE extension']];
        yield 'enumeration case' => ["1 FAMC @F1@\n2 PEDI BIRTH\n2 STAT PROVEN\n", "1 FAMC @F1@\n2 PEDI birth\n2 STAT proven\n", []];
        yield 'other with phrase' => ["1 NAME Anna\n2 TYPE OTHER\n3 PHRASE Screen\n", "1 NAME Anna\n2 TYPE Screen\n", []];
        yield 'list' => ["1 RESN CONFIDENTIAL, LOCKED\n", "1 RESN confidential, locked\n", []];
        yield 'relation' => ["1 ASSO @I2@\n2 ROLE GODP\n3 PHRASE godmother\n1 ASSO @I2@\n2 ROLE FRIEND\n1 ASSO @I2@\n2 ROLE OTHER\n3 PHRASE land-lord\n",
            "1 ASSO @I2@\n2 RELA godmother\n1 ASSO @I2@\n2 RELA Friend\n1 ASSO @I2@\n2 RELA land-lord\n", []];
        yield 'role description' => ["1 BIRT\n2 SOUR @S1@\n3 EVEN BIRT\n4 ROLE OTHER\n5 PHRASE midwife\n", "1 BIRT\n2 SOUR @S1@\n3 EVEN BIRT\n4 ROLE (midwife)\n", []];
        yield 'ordinance status' => ["1 BAPL\n2 STAT PRE_1970\n3 DATE 1 JAN 1960\n", "1 BAPL\n2 STAT PRE-1970\n3 DATE 1 JAN 1960\n", []];
        yield 'status 5.5.1 does not have' => ["1 CONL\n2 STAT INFANT\n3 DATE 1 JAN 1960\n", "1 CONL\n2 _STAT INFANT\n3 DATE 1 JAN 1960\n", ['KL3001 @I1@/CONL/STAT extension']];
        yield 'time in UTC' => ["1 CHAN\n2 DATE 1 JAN 2020\n3 TIME 10:00Z\n", "1 CHAN\n2 DATE 1 JAN 2020\n3 _TIME 10:00Z\n", ['KL3001 @I1@/CHAN/DATE/TIME extension']];
        yield 'age phrase only' => ["1 DEAT\n2 AGE\n3 PHRASE Zero\n", "1 DEAT\n2 _AGE\n3 PHRASE Zero\n", ['KL3001 @I1@/DEAT/AGE extension']];
        yield 'residence' => ["1 RESI Kyiv\n2 DATE 1900\n", "1 RESI\n2 DATE 1900\n2 NOTE Kyiv\n", ['KL3005 @I1@/RESI note']];
        yield 'sealing to no family' => ["1 SLGC\n2 DATE 1 JAN 2000\n3 TIME 10:00\n2 FAMC @VOID@\n", "1 _SLGC\n2 DATE 1 JAN 2000\n3 TIME 10:00\n2 FAMC @VOID@\n", ['KL4001 @I1@/SLGC extension']];
    }

    /** @param list<string> $losses */
    #[Test]
    #[DataProvider('values')]
    public function valuesConvertDownAndBack(string $seven, string $five, array $losses): void
    {
        $tail = "0 @I2@ INDI\n0 @F1@ FAM\n0 @S1@ SOUR\n0 TRLR\n";
        $input = self::HEAD . "0 @I1@ INDI\n{$seven}{$tail}";
        $down = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1);

        self::assertSame("0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @I1@ INDI\n{$five}{$tail}", $down->output());
        self::assertSame($losses, array_map(static fn(\Korin\Gedcom\Conversion\Loss $loss): string => "{$loss->code} {$loss->path} {$loss->storedAs->value}", $down->losses()->entries()));
        if (!str_starts_with($losses[0] ?? '', 'KL3')) {
            self::assertSame($input, Gedcom::convert($down->document(), Version::V7_0)->output(), 'up again');
        }
    }

    #[Test]
    public function languagesTakeTheirFiveFiveOneNames(): void
    {
        $down = Gedcom::convert(Gedcom::parse(self::HEAD . "1 LANG uk\n1 SCHMA\n2 TAG _PHRASE https://gedcom.io/terms/v7/PHRASE\n0 @U1@ SUBM\n1 NAME Me\n1 LANG und\n2 _PHRASE Hmong\n0 @U2@ SUBM\n1 NAME You\n1 LANG en-GB\n0 TRLR\n"), Version::V5_5_1);

        self::assertStringContainsString("1 LANG Ukrainian\n", $down->output());
        self::assertStringContainsString("1 NAME Me\n1 LANG Hmong\n", $down->output());
        self::assertStringContainsString("1 NAME You\n1 _LANG en-GB\n", $down->output());
        self::assertSame(['KL2001', 'KL3001'], array_map(static fn(\Korin\Gedcom\Conversion\Loss $loss): string => $loss->code, $down->losses()->entries()));
    }

    #[Test]
    public function aSubmitterBeyondTheThreeLanguagesKeepsItsTag(): void
    {
        $down = Gedcom::convert(Gedcom::parse(self::HEAD . "0 @U1@ SUBM\n1 NAME Me\n1 LANG uk\n1 LANG en\n1 LANG de\n1 LANG fr\n0 TRLR\n"), Version::V5_5_1);

        self::assertStringContainsString("1 LANG Ukrainian\n1 LANG English\n1 LANG German\n1 _LANG fr\n", $down->output());
        self::assertSame(['KL2002'], array_map(static fn(\Korin\Gedcom\Conversion\Loss $loss): string => $loss->code, $down->losses()->entries()));
    }

    #[Test]
    public function aRecordTheSchemaDefinesAsStandardGetsItsTag(): void
    {
        $input = self::HEAD . "1 SCHMA\n2 TAG _USER https://gedcom.io/terms/v7/record-SUBM\n0 @U1@ _USER\n1 NAME Me\n0 @I1@ INDI\n1 SUBM @U1@\n0 TRLR\n";
        $down = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1);

        self::assertStringContainsString("0 @U1@ SUBM\n1 NAME Me\n", $down->output());
        self::assertNotContains('KV4003', array_map(static fn(Diagnostic $error): string => $error->code, Gedcom::validate($down->document())->errors()), 'INDI.SUBM points to a SUBM record');
    }

    /** @return iterable<string, array{string, string}> */
    public static function files(): iterable
    {
        yield 'drive' => ['file:///c:/My%20Photos/anna.jpg', 'c:\\My Photos\\anna.jpg'];
        yield 'absolute' => ['file:///home/anna/x.gif', '/home/anna/x.gif'];
        yield 'server' => ['file://server/share/x.tif', '\\\\server\\share\\x.tif'];
        yield 'relative' => ['photos/a%20b.png', 'photos/a b.png'];
        yield 'url' => ['https://example.com/x%20y.pdf', 'https://example.com/x%20y.pdf'];
        yield 'not what converting up writes' => ['file:///c:/a%2fb', 'file:///c:/a%2fb'];
    }

    #[Test]
    #[DataProvider('files')]
    public function filesBecomePaths(string $url, string $path): void
    {
        self::assertSame($path, (new Media())->path($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function formats(): iterable
    {
        yield 'listed' => ['image/jpeg', 'jpg'];
        yield 'tiff' => ['image/tiff', 'tif'];
        yield 'known' => ['image/png', 'png'];
        yield 'x-' => ['application/x-other', 'other'];
        yield 'unknown' => ['application/vnd.example', 'application/vnd.example'];
    }

    #[Test]
    #[DataProvider('formats')]
    public function mediaTypesBecomeExtensions(string $type, string $extension): void
    {
        self::assertSame($extension, (new Media())->extension($type));
    }

    #[Test]
    public function dateWords(): void
    {
        self::assertSame('@#DROMAN@ 24 IUL 15', (new Dates())->down('_ROMAN 24 _IUL 15'));
        self::assertNull((new Dates())->down('24 _IUL 15'), 'an extension month without its calendar');
        self::assertNull((new Dates())->down('_MAYAN 1000'));
    }
}

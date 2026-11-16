<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\ConversionResult;
use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V555ToV551Test extends TestCase
{
    private const HEAD = "\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME Me\n";
    private const HEAD_551 = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME Me\n";

    #[Test]
    public function theVersionOfTheFormIsRemoved(): void
    {
        $result = $this->up(self::HEAD . "0 TRLR\n");

        self::assertSame(self::HEAD_551 . "0 TRLR\n", $result->output());
        self::assertSame([], $result->losses()->entries());
    }

    #[Test]
    public function addressesGetTheirValueBack(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n"
            . "1 RESI\n2 ADDR\n3 ADR1 12 Main St\n3 ADR2 apt. 4\n3 CITY Kyiv\n"
            . "1 RESI\n2 ADDR\n3 CITY Kyiv\n3 STAE Kyiv Oblast\n3 POST 01001\n3 CTRY Ukraine\n"
            . "1 RESI\n2 ADDR\n3 _ADDR 1 Rynok Sq\n4 CONT Lviv\n3 ADR1 Rynok Square 1\n0 TRLR\n";
        $result = $this->up($input);

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n"
            . "1 RESI\n2 ADDR 12 Main St\n3 CONT apt. 4\n3 ADR1 12 Main St\n3 ADR2 apt. 4\n3 CITY Kyiv\n"
            . "1 RESI\n2 ADDR Kyiv, Kyiv Oblast 01001, Ukraine\n3 CITY Kyiv\n3 STAE Kyiv Oblast\n3 POST 01001\n3 CTRY Ukraine\n"
            . "1 RESI\n2 ADDR 1 Rynok Sq\n3 CONT Lviv\n3 ADR1 Rynok Square 1\n0 TRLR\n", $result->output());
        self::assertSame([], $result->losses()->entries());
        self::assertSame([], Gedcom::validate($result->document())->diagnostics());
        self::assertSame($input, Gedcom::convert($result->document(), Version::V5_5_5)->output());
    }

    #[Test]
    public function sexNIsSexUWithAnExtension(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n1 SEX N\n0 @I2@ INDI\n1 SEX X\n0 TRLR\n";
        $result = $this->up($input);

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 SEX U\n2 _SEX N\n0 @I2@ INDI\n1 SEX U\n2 _SEX X\n0 TRLR\n", $result->output());
        self::assertSame(['KL3002 warning line 12 (@I1@/SEX)', 'KL3002 warning line 14 (@I2@/SEX)'], $this->where($result));
        self::assertSame($input, Gedcom::convert($result->document(), Version::V5_5_5)->output());
    }

    #[Test]
    public function valuesTakeTheFiveFiveOneSpelling(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n1 BIRT\n2 DATE 5 MAR\n2 PLAC Kyiv\n1 DEAT\n2 DATE ABT 050\n2 AGE < 1y\n1 BURI\n2 DATE 1000 BCE\n"
            . "0 @O1@ OBJE\n1 FILE a.jpeg\n2 FORM JPEG\n0 @O2@ OBJE\n1 FILE b.png\n2 FORM PNG\n0 TRLR\n";
        $result = $this->up($input);

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 BIRT\n2 _DATE 5 MAR\n2 PLAC Kyiv\n1 DEAT\n2 DATE ABT 50\n2 AGE <1y\n1 BURI\n2 DATE 1000 B.C.\n"
            . "0 @O1@ OBJE\n1 FILE a.jpeg\n2 FORM jpg\n0 @O2@ OBJE\n1 FILE b.png\n2 FORM PNG\n0 TRLR\n", $result->output());
        self::assertSame(['KL3001 warning line 13 (@I1@/BIRT/DATE)'], $this->where($result));
        self::assertSame(str_replace(['1000 BCE', 'FORM JPEG'], ['1000 B.C.', 'FORM jpg'], $input), Gedcom::convert($result->document(), Version::V5_5_5)->output());
    }

    #[Test]
    public function fiveFiveFiveConvertsToSevenThroughFiveFiveOne(): void
    {
        $result = Gedcom::convert(Gedcom::parse(self::HEAD . "0 @I1@ INDI\n1 SEX N\n1 DEAT\n2 DATE FROM 050 BCE TO 040 BCE\n2 AGE < 1y\n0 TRLR\n"), Version::V7_0);

        self::assertSame("\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 7.0\n1 SOUR Korin\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME Me\n"
            . "0 @I1@ INDI\n1 SEX U\n2 _SEX N\n1 DEAT\n2 DATE FROM 50 BCE TO 40 BCE\n2 AGE < 1y\n0 TRLR\n", $result->output());
        self::assertSame(['KL3002 warning line 12 (@I1@/SEX)'], $this->where($result));
    }

    private function up(string $gedcom): ConversionResult
    {
        return Gedcom::convert(Gedcom::parse($gedcom), Version::V5_5_1);
    }

    /** @return list<string> */
    private function where(ConversionResult $result): array
    {
        return array_map(static fn(Loss $loss): string => explode(',', (string) $loss)[0], $result->losses()->entries());
    }
}

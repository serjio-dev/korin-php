<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\ConversionResult;
use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V55ToV551Test extends TestCase
{
    private const HEAD = "0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 5.5\n2 FORM LINEAGE-LINKED\n1 CHAR ASCII\n";
    private const HEAD_551 = "0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n";

    #[Test]
    public function eventsLoseTheYFiveFiveOneDoesNotHave(): void
    {
        $result = $this->up(self::HEAD . "0 @I1@ INDI\n1 BIRT Y\n1 BURI Y\n1 CREM Y\n2 DATE 1950\n0 @F1@ FAM\n1 DIV Y\n0 TRLR\n");

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 BIRT Y\n1 BURI\n1 CREM\n2 DATE 1950\n0 @F1@ FAM\n1 DIV\n0 TRLR\n", $result->output());
        self::assertSame(['KL3003 warning line 10 (@I1@/CREM)'], $this->where($result));
    }

    #[Test]
    public function structuresFiveFiveOneDoesNotHaveBecomeExtensions(): void
    {
        $result = $this->up(self::HEAD . "0 @I1@ INDI\n1 FAMC @F1@\n2 PEDI birth\n2 PEDI adopted\n1 NOTE Text\n2 SOUR Family Bible\n1 BAPL\n2 STAT CLEARED\n"
            . "1 BIRT\n2 PLAC Kyiv\n3 SOUR @S1@\n0 @F1@ FAM\n1 MARR\n2 AGE 25y\n2 HUSB\n3 AGE 30y\n0 @S1@ SOUR\n0 TRLR\n");

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 FAMC @F1@\n2 PEDI birth\n2 _PEDI adopted\n1 NOTE Text\n2 _SOUR Family Bible\n1 BAPL\n2 _STAT CLEARED\n"
            . "1 BIRT\n2 PLAC Kyiv\n3 _SOUR @S1@\n0 @F1@ FAM\n1 MARR\n2 _AGE 25y\n2 HUSB\n3 AGE 30y\n0 @S1@ SOUR\n0 TRLR\n", $result->output());
        self::assertSame(['KL2002 warning line 10 (@I1@/FAMC/PEDI[1])', 'KL2001 warning line 12 (@I1@/NOTE/SOUR)', 'KL3001 warning line 14 (@I1@/BAPL/STAT)',
            'KL2001 warning line 17 (@I1@/BIRT/PLAC/SOUR)', 'KL2001 warning line 20 (@F1@/MARR/AGE)'], $this->where($result));
    }

    #[Test]
    public function associationsAreWithIndividuals(): void
    {
        $result = $this->up(self::HEAD . "0 @I1@ INDI\n1 ASSO @I2@\n2 TYPE INDI\n2 RELA Godfather\n1 ASSO @F1@\n2 TYPE FAM\n2 RELA Witness\n0 @I2@ INDI\n0 @F1@ FAM\n0 TRLR\n");

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 ASSO @I2@\n2 RELA Godfather\n1 _ASSO @F1@\n2 TYPE FAM\n2 RELA Witness\n0 @I2@ INDI\n0 @F1@ FAM\n0 TRLR\n", $result->output());
        self::assertSame(['KL2001 warning line 11 (@I1@/ASSO[1])'], $this->where($result));
    }

    #[Test]
    public function mediaFormsBelongToTheFile(): void
    {
        $result = $this->up(self::HEAD . "0 @I1@ INDI\n1 OBJE\n2 FORM jpeg\n2 TITL Portrait\n2 FILE photos/john.jpg\n2 NOTE Taken in 1940\n"
            . "0 @O1@ OBJE\n1 FORM tiff\n1 TITL Scan\n1 BLOB\n2 CONT .HM.......k.1..F.jo\n1 OBJE @O2@\n0 @O2@ OBJE\n1 FORM bmp\n1 TITL Letter\n1 FILE letter.bmp\n0 TRLR\n");

        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 OBJE\n2 TITL Portrait\n2 FILE photos/john.jpg\n3 FORM jpg\n2 _NOTE Taken in 1940\n"
            . "0 @O1@ OBJE\n1 _FORM tiff\n1 _TITL Scan\n1 _BLOB\n2 CONT .HM.......k.1..F.jo\n1 _OBJE @O2@\n0 @O2@ OBJE\n1 FILE letter.bmp\n2 FORM bmp\n2 TITL Letter\n0 TRLR\n", $result->output());
        self::assertSame(['KL2001 warning line 12 (@I1@/OBJE/NOTE)', 'KL2001 warning line 14 (@O1@/FORM)', 'KL2001 warning line 15 (@O1@/TITL)',
            'KL2001 warning line 16 (@O1@/BLOB)', 'KL2001 warning line 18 (@O1@/OBJE)'], $this->where($result));
    }

    #[Test]
    public function fiveFiveConvertsToSevenThroughFiveFiveOne(): void
    {
        $result = Gedcom::convert(Gedcom::parse(self::HEAD . "1 LANG English\n0 @I1@ INDI\n1 NAME John /Smith/\n2 NOTE 50 @ 1\n1 BURI Y\n1 BIRT\n2 DATE 1 jan 1900\n"
            . "1 OBJE\n2 FORM jpeg\n2 FILE C:\\photos\\john.jpg\n1 NOTE @N1@\n0 @N1@ NOTE Shared\n0 TRLR\n"), Version::V7_0);

        self::assertSame("\u{FEFF}0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 7.0\n1 LANG en\n0 @I1@ INDI\n1 NAME John /Smith/\n2 NOTE 50 @ 1\n1 BURI Y\n1 BIRT\n2 DATE 1 JAN 1900\n"
            . "1 OBJE @X1@\n1 SNOTE @N1@\n0 @N1@ SNOTE Shared\n0 @X1@ OBJE\n1 FILE file:///C:/photos/john.jpg\n2 FORM image/jpeg\n0 TRLR\n", $result->output());
        self::assertSame([], $result->losses()->entries());
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

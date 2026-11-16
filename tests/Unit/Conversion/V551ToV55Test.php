<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\ConversionResult;
use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V551ToV55Test extends TestCase
{
    private const HEAD = "0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n";
    private const HEAD_55 = "0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 5.5\n2 FORM LINEAGE-LINKED\n1 CHAR UNICODE\n";

    #[Test]
    public function theOutputIsUtf16WithCharUnicode(): void
    {
        $result = $this->down(self::HEAD . "0 TRLR\n");

        self::assertStringStartsWith("\xFE\xFF\x000\x00 \x00H", $result->output());
        self::assertSame(self::HEAD_55 . "0 TRLR\n", $this->text($result));
        self::assertSame([], $result->losses()->entries());
    }

    #[Test]
    public function structuresFiveFiveDoesNotHaveBecomeExtensions(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n1 NAME Taro /Yamada/\n2 TYPE birth\n2 ROMN Taro /Yamada/\n3 TYPE romaji\n"
            . "1 BIRT\n2 PLAC Tokyo\n3 MAP\n4 LATI N35.6\n4 LONG E139.7\n2 RELI Shinto\n2 EMAIL a@example.org\n1 FACT Tall\n2 TYPE Height\n"
            . "1 FAMC @F1@\n2 STAT proven\n0 @F1@ FAM\n1 RESN privacy\n0 @S1@ SOUR\n1 REPO @R1@\n1 REPO @R2@\n1 REPO\n2 CALN 12\n"
            . "0 @R1@ REPO\n1 NAME A\n0 @R2@ REPO\n1 NAME B\n0 TRLR\n";
        $result = $this->down($input);

        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 NAME Taro /Yamada/\n2 _TYPE birth\n2 _ROMN Taro /Yamada/\n3 TYPE romaji\n"
            . "1 BIRT\n2 PLAC Tokyo\n3 _MAP\n4 LATI N35.6\n4 LONG E139.7\n2 _RELI Shinto\n2 _EMAIL a@example.org\n1 _FACT Tall\n2 TYPE Height\n"
            . "1 FAMC @F1@\n2 _STAT proven\n0 @F1@ FAM\n1 _RESN privacy\n0 @S1@ SOUR\n1 REPO @R1@\n1 _REPO @R2@\n1 _REPO\n2 CALN 12\n"
            . "0 @R1@ REPO\n1 NAME A\n0 @R2@ REPO\n1 NAME B\n0 TRLR\n", $this->text($result));
        self::assertSame(['KL2001 warning line 9 (@I1@/NAME/TYPE)', 'KL2001 warning line 10 (@I1@/NAME/ROMN)', 'KL2001 warning line 14 (@I1@/BIRT/PLAC/MAP)',
            'KL2001 warning line 17 (@I1@/BIRT/RELI)', 'KL2001 warning line 18 (@I1@/BIRT/EMAIL)', 'KL2001 warning line 19 (@I1@/FACT)', 'KL2001 warning line 22 (@I1@/FAMC/STAT)',
            'KL2001 warning line 24 (@F1@/RESN)', 'KL2002 warning line 27 (@S1@/REPO[1])', 'KL3001 warning line 28 (@S1@/REPO[2])'], $this->where($result));
        self::assertSame($input, Gedcom::serialize(Gedcom::convert($result->document(), Version::V5_5_1)->document()));
    }

    #[Test]
    public function valuesFiveFiveDoesNotListBecomeExtensions(): void
    {
        $result = $this->down(self::HEAD . "0 @I1@ INDI\n1 RESN confidential\n1 SEX U\n1 BAPL\n2 STAT EXCLUDED\n3 DATE 1 JAN 2000\n"
            . "1 OBJE\n2 FILE a.png\n3 FORM png\n0 @I2@ INDI\n1 SEX F\n0 TRLR\n");

        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 _RESN confidential\n1 _SEX U\n1 BAPL\n2 _STAT EXCLUDED\n3 DATE 1 JAN 2000\n"
            . "1 OBJE\n2 FORM png\n2 FILE a.png\n0 @I2@ INDI\n1 SEX F\n0 TRLR\n", $this->text($result));
        self::assertSame(['KL3001 warning line 8 (@I1@/RESN)', 'KL3001 warning line 9 (@I1@/SEX)', 'KL3001 warning line 11 (@I1@/BAPL/STAT)'], $this->where($result));
    }

    #[Test]
    public function theValueOfEvenIsItsTypeOrANote(): void
    {
        $result = $this->down(self::HEAD . "0 @I1@ INDI\n1 EVEN Moved to Osaka\n2 DATE 1950\n1 EVEN Served in the navy\n2 TYPE Military\n0 @F1@ FAM\n1 EVEN\n2 TYPE Feud\n0 TRLR\n");

        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 EVEN\n2 TYPE Moved to Osaka\n2 DATE 1950\n1 EVEN\n2 TYPE Military\n2 NOTE Served in the navy\n"
            . "0 @F1@ FAM\n1 EVEN\n2 TYPE Feud\n0 TRLR\n", $this->text($result));
        self::assertSame(['KL3004 warning line 8 (@I1@/EVEN[0]), moved', 'KL3004 warning line 10 (@I1@/EVEN[1]), note'], array_map(
            static fn(Loss $loss): string => explode(':', (string) $loss)[0],
            $result->losses()->entries(),
        ));
    }

    #[Test]
    public function mediaFormsBelongToTheObject(): void
    {
        $result = $this->down(self::HEAD . "0 @I1@ INDI\n1 OBJE\n2 FILE a.jpg\n3 FORM jpg\n4 MEDI photo\n2 FILE b.tif\n3 FORM tif\n2 TITL Two\n1 OBJE @O1@\n"
            . "0 @O1@ OBJE\n1 FILE photos/taro.jpg\n2 FORM jpg\n3 TYPE photo\n2 TITL Portrait\n1 FILE photos/taro.tif\n2 FORM tif\n1 REFN 7\n0 TRLR\n");

        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 OBJE\n2 FORM jpeg\n3 _MEDI photo\n2 FILE a.jpg\n2 _FILE b.tif\n3 FORM tif\n2 TITL Two\n1 OBJE @O1@\n"
            . "0 @O1@ OBJE\n1 FORM jpeg\n2 _TYPE photo\n1 TITL Portrait\n1 _FILE photos/taro.jpg\n1 _FILE photos/taro.tif\n2 FORM tif\n1 REFN 7\n0 TRLR\n", $this->text($result));
        self::assertSame(['KL2001 warning line 11 (@I1@/OBJE[0]/FILE[0]/FORM/MEDI)', 'KL2002 warning line 12 (@I1@/OBJE[0]/FILE[1])', 'KL2001 warning line 19 (@O1@/FILE[0]/FORM/TYPE)',
            'KL2001 warning line 17 (@O1@/FILE[0])', 'KL2001 warning line 21 (@O1@/FILE[1])'], $this->where($result));

        $up = Gedcom::serialize(Gedcom::convert($result->document(), Version::V5_5_1)->document());
        self::assertSame(self::HEAD . "0 @I1@ INDI\n1 OBJE\n2 FILE a.jpg\n3 FORM jpg\n4 MEDI photo\n2 FILE b.tif\n3 FORM tif\n2 TITL Two\n1 OBJE @O1@\n"
            . "0 @O1@ OBJE\n1 FILE photos/taro.jpg\n2 FORM jpg\n3 TYPE photo\n2 TITL Portrait\n1 FILE photos/taro.tif\n2 FORM tif\n1 REFN 7\n0 TRLR\n", $up);
    }

    #[Test]
    public function associationsNameTheTypeOfTheRecord(): void
    {
        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 ASSO @I2@\n2 TYPE INDI\n2 RELA Friend\n0 @I2@ INDI\n0 TRLR\n", $this->text($this->down(
            self::HEAD . "0 @I1@ INDI\n1 ASSO @I2@\n2 RELA Friend\n0 @I2@ INDI\n0 TRLR\n",
        )));
    }

    #[Test]
    public function dropWithReportRemovesWhatFiveFiveDoesNotHave(): void
    {
        $result = Gedcom::convert(Gedcom::parse(self::HEAD . "0 @I1@ INDI\n1 SEX U\n1 BIRT\n2 EMAIL a@example.org\n2 DATE 1900\n0 TRLR\n"), Version::V5_5, (new ConvertOptions())->withPolicy(LossPolicy::DropWithReport));

        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 BIRT\n2 DATE 1900\n0 TRLR\n", $this->text($result));
        self::assertSame(['KL3001 warning line 8 (@I1@/SEX)', 'KL2001 warning line 10 (@I1@/BIRT/EMAIL)'], $this->where($result));
    }

    #[Test]
    public function sevenConvertsThroughFiveFiveOne(): void
    {
        $input = "\u{FEFF}0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 SEX X\n1 BURI Y\n1 BIRT\n2 DATE 1900\n2 PLAC Tokyo\n3 TRAN Tokyo\n4 LANG ja-Latn\n"
            . "1 OBJE @O1@\n0 @O1@ OBJE\n1 FILE photos/a.jpg\n2 FORM image/jpeg\n0 TRLR\n";
        $down = Gedcom::convert(Gedcom::parse($input), Version::V5_5);

        self::assertSame(self::HEAD_55 . "0 @I1@ INDI\n1 _SEX U\n2 _SEX X\n1 BURI\n1 BIRT\n2 DATE 1900\n2 PLAC Tokyo\n3 _ROMN Tokyo\n4 TYPE romaji\n"
            . "1 OBJE @O1@\n0 @O1@ OBJE\n1 FORM jpeg\n1 _FILE photos/a.jpg\n0 TRLR\n", $this->text($down));
        self::assertSame($input, Gedcom::convert($down->document(), Version::V7_0)->output());
    }

    private function down(string $gedcom): ConversionResult
    {
        return Gedcom::convert(Gedcom::parse($gedcom), Version::V5_5);
    }

    private function text(ConversionResult $result): string
    {
        return (string) Codecs::for(Encoding::Utf16Be)->decode(substr($result->output(), 2));
    }

    /** @return list<string> */
    private function where(ConversionResult $result): array
    {
        return array_map(static fn(Loss $loss): string => explode(',', (string) $loss)[0], $result->losses()->entries());
    }
}

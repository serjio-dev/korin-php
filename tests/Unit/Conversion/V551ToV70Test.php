<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V551ToV70Test extends TestCase
{
    private const HEAD = "0 HEAD\n1 SOUR Korin\n1 DATE 1 JAN 2020\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR ANSEL\n2 VERS 1985\n";
    private const HEAD_70 = "\u{FEFF}0 HEAD\n1 SOUR Korin\n1 DATE 1 JAN 2020\n1 GEDC\n2 VERS 7.0\n";

    #[Test]
    public function charAndGedcFormAreRemovedSourceAndDateKept(): void
    {
        self::assertSame(self::HEAD_70 . "0 TRLR\n", $this->convert(self::HEAD . "0 TRLR\n"));
    }

    #[Test]
    public function xrefsInvalidInSevenGetTheNextFreeNameInRecordOrder(): void
    {
        $output = $this->convert(self::HEAD . "1 SUBM @sub@\n"
            . "0 @1@ INDI\n1 FAMS @fam 1@\n1 _FRIEND @VOID@\n1 ASSO @1@\n"
            . "0 @X1@ INDI\n1 FAMC @x1@\n"
            . "0 @fam 1@ FAM\n1 HUSB @1@\n"
            . "0 @VOID@ INDI\n"
            . "0 @sub@ SUBM\n1 NAME Korin\n"
            . "0 TRLR\n");

        self::assertSame(self::HEAD_70 . "1 SUBM @X4@\n"
            . "0 @1@ INDI\n1 FAMS @X2@\n1 _FRIEND @X3@\n1 ASSO @1@\n"
            . "0 @X1@ INDI\n1 FAMC @VOID@\n"
            . "0 @X2@ FAM\n1 HUSB @1@\n"
            . "0 @X3@ INDI\n"
            . "0 @X4@ SUBM\n1 NAME Korin\n"
            . "0 TRLR\n", $output);
    }

    #[Test]
    public function noteRecordsAndPointersToThemBecomeSharedNotes(): void
    {
        $output = $this->convert(self::HEAD
            . "0 @I1@ INDI\n1 NOTE @N1@\n1 NOTE Inline text\n1 BIRT\n2 NOTE @N1@\n1 NOTE @Smith family@\n"
            . "0 @N1@ NOTE Shared\n"
            . "0 TRLR\n");

        self::assertSame(self::HEAD_70
            . "0 @I1@ INDI\n1 SNOTE @N1@\n1 NOTE Inline text\n1 BIRT\n2 SNOTE @N1@\n1 NOTE @@Smith family@\n"
            . "0 @N1@ SNOTE Shared\n"
            . "0 TRLR\n", $output);
    }

    #[Test]
    public function concIsJoinedAndAtSignsFollowTheSevenRules(): void
    {
        $long = str_repeat('Long text. ', 30);
        $output = $this->convert(self::HEAD . "0 @I1@ INDI\n"
            . "1 NOTE One @@ inside, e-mail a@@b.c, @#DJULIAN@ kept as text. Spl\n2 CONC it word\n2 CONT @@ leading on CONT\n2 CONT\n"
            . "1 NOTE @@ leading, @#DJULIAN@ inside\n"
            . "1 NOTE @#DJULIAN@ leading escape\n"
            . "1 NOTE Plain\n2 CONT second line\n"
            . "1 BIRT\n2 DATE @#DJULIAN@ 1 JAN 1700\n"
            . '1 NOTE ' . substr($long, 0, 200) . "\n2 CONC " . substr($long, 200) . "\n"
            . "0 TRLR\n");

        self::assertSame(self::HEAD_70 . "0 @I1@ INDI\n"
            . "1 NOTE One @ inside, e-mail a@b.c, @#DJULIAN@ kept as text. Split word\n2 CONT @@ leading on CONT\n2 CONT\n"
            . "1 NOTE @@ leading, @#DJULIAN@ inside\n"
            . "1 NOTE @@#DJULIAN@ leading escape\n"
            . "1 NOTE Plain\n2 CONT second line\n"
            . "1 BIRT\n2 DATE JULIAN 1 JAN 1700\n"
            . '1 NOTE ' . $long . "\n"
            . "0 TRLR\n", $output);
    }

    #[Test]
    public function submissionRecordsMoveIntoTheHeader(): void
    {
        $output = $this->convert(self::HEAD . "1 SUBN @S1@\n"
            . "0 @S1@ SUBN\n1 FAMF Koval\n1 NOTE a@@b\n"
            . "0 @I1@ INDI\n"
            . "0 @S2@ SUBN\n1 ANCE 2\n"
            . "0 TRLR\n");

        self::assertSame(self::HEAD_70
            . "1 _SUBN\n2 FAMF Koval\n2 NOTE a@b\n1 _SUBN\n2 ANCE 2\n"
            . "0 @I1@ INDI\n"
            . "0 TRLR\n", $output);
    }

    #[Test]
    public function inlineMediaAndSourcesBecomeRecords(): void
    {
        $output = $this->convert(self::HEAD
            . "0 @I1@ INDI\n"
            . "1 OBJE\n2 FILE a.jpg\n3 FORM jpg\n2 TITL Portrait\n2 _MARK x\n"
            . "1 SOUR Letters of\n2 CONC  1920\n2 CONT and @@ 1921\n2 TEXT Dear Anna\n2 NOTE @N1@\n"
            . "1 SOUR @S1@\n2 PAGE 3\n"
            . "1 _X\n2 SOUR kept inside an extension\n"
            . "0 @N1@ NOTE n\n0 @S1@ SOUR\n1 TITL Register\n"
            . "0 TRLR\n");

        self::assertSame(self::HEAD_70
            . "0 @I1@ INDI\n"
            . "1 OBJE @X1@\n2 TITL Portrait\n"
            . "1 SOUR @X2@\n2 DATA\n3 TEXT Dear Anna\n2 SNOTE @N1@\n"
            . "1 SOUR @S1@\n2 PAGE 3\n"
            . "1 _X\n2 SOUR kept inside an extension\n"
            . "0 @N1@ SNOTE n\n0 @S1@ SOUR\n1 TITL Register\n"
            . "0 @X1@ OBJE\n1 FILE a.jpg\n2 FORM image/jpeg\n1 _MARK x\n"
            . "0 @X2@ SOUR\n1 NOTE Letters of 1920\n2 CONT and @ 1921\n"
            . "0 TRLR\n", $output);
    }

    #[Test]
    public function identifiersBecomeExidAndUidLosesItsUnderscore(): void
    {
        $head = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n";
        $output = $this->convert($head . "0 @I1@ INDI\n1 AFN 1A\n1 RFN reg:9\n1 RIN 42\n1 _UID 0A1B\n1 _X\n2 RIN 5\n2 _UID 1\n0 TRLR\n");

        self::assertSame("\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n"
            . "1 EXID 1A\n2 TYPE https://gedcom.io/terms/v7/AFN\n"
            . "1 EXID reg\n2 TYPE https://gedcom.io/terms/v7/RFN#9\n"
            . "1 EXID 42\n2 TYPE https://gedcom.io/terms/v7/RIN\n"
            . "1 UID 0A1B\n1 _X\n2 RIN 5\n2 _UID 1\n0 TRLR\n", $output);
    }

    #[Test]
    public function valuesFollowTheSevenTypes(): void
    {
        $output = $this->convert(self::HEAD . "1 LANG ukrainian\n"
            . "0 @I1@ INDI\n1 SEX m\n1 NAME Anna /Koval/\n2 TYPE Screen\n1 FAMC @F1@\n2 PEDI birth\n"
            . "1 BIRT\n2 DATE int 1699/00 (as told)\n2 AGE <0Y\n"
            . "1 DEAT\n2 DATE (in the war)\n2 AGE stillborn\n"
            . "1 ASSO @I2@\n2 RELA neighbor\n"
            . "1 OBJE @O1@\n1 _X\n2 DATE @#DJULIAN@ 1700\n"
            . "1 CHAN\n2 DATE 1 jan 2020\n"
            . "0 @I2@ INDI\n0 @F1@ FAM\n1 CHIL @I1@\n"
            . "0 @O1@ OBJE\n1 FILE c:\\photos\\my file.jpg\n2 FORM JPG\n3 TYPE photo\n"
            . "0 @S1@ SUBM\n1 NAME Korin\n1 LANG Hmong\n"
            . "0 TRLR\n");

        self::assertSame(self::HEAD_70 . "1 LANG uk\n1 SCHMA\n2 TAG _PHRASE https://gedcom.io/terms/v7/PHRASE\n"
            . "0 @I1@ INDI\n1 SEX M\n1 NAME Anna /Koval/\n2 TYPE OTHER\n3 PHRASE Screen\n1 FAMC @F1@\n2 PEDI BIRTH\n"
            . "1 BIRT\n2 DATE 1700\n3 PHRASE int 1699/00 (as told)\n2 AGE < 0y\n"
            . "1 DEAT\n2 DATE\n3 PHRASE in the war\n2 AGE 0y\n3 PHRASE stillborn\n"
            . "1 ASSO @I2@\n2 ROLE NGHBR\n3 PHRASE neighbor\n"
            . "1 OBJE @O1@\n1 _X\n2 DATE @@#DJULIAN@ 1700\n"
            . "1 CHAN\n2 DATE 1 JAN 2020\n"
            . "0 @I2@ INDI\n0 @F1@ FAM\n1 CHIL @I1@\n"
            . "0 @O1@ OBJE\n1 FILE file:///c:/photos/my%20file.jpg\n2 FORM image/jpeg\n3 MEDI PHOTO\n"
            . "0 @S1@ SUBM\n1 NAME Korin\n1 LANG und\n2 _PHRASE Hmong\n"
            . "0 TRLR\n", $output);
    }

    #[Test]
    public function valuesAreConvertedByTheirStructureNotByTag(): void
    {
        $output = $this->convert(self::HEAD . "1 LANG en-GB\n0 @S1@ SOUR\n1 DATA\n2 EVEN BIRT\n3 DATE 1850\n"
            . "0 @I1@ INDI\n1 EVEN Fair\n2 DATE 1850\n2 TYPE Show\n1 SEX M\n2 _NOTE u\n0 TRLR\n");

        self::assertSame(self::HEAD_70 . "1 LANG en-GB\n0 @S1@ SOUR\n1 DATA\n2 EVEN BIRT\n3 DATE\n4 PHRASE 1850\n"
            . "0 @I1@ INDI\n1 EVEN Fair\n2 DATE 1850\n2 TYPE Show\n1 SEX M\n2 _NOTE u\n0 TRLR\n", $output);
    }

    #[Test]
    public function valuesAreReadAfterTheLineSyntaxIsConverted(): void
    {
        $output = $this->convert(self::HEAD . "0 @I1@ INDI\n1 BIRT\n2 DATE ABT 19\n3 CONC 00\n1 DEAT\n2 DATE 1 1900\n"
            . "1 ASSO @I1@\n2 RELA god@@@@mother\n1 ASSO @I1@\n2 RELA\n1 ASSO @I1@\n2 RELA (godmother)\n"
            . "1 ASSO @I1@\n2 RELA _GODCHILD\n"
            . "1 OBJE\n2 FILE a@@@@b c.jpg\n3 FORM jpg\n2 FILE C://photos/a.jpg\n3 FORM jpg\n"
            . "0 @S1@ SOUR\n1 DATA\n2 EVEN birt,dns/can\n2 EVEN birt, deat\n0 TRLR\n");

        self::assertSame(self::HEAD_70 . "0 @I1@ INDI\n1 BIRT\n2 DATE ABT 1900\n1 DEAT\n2 DATE\n3 PHRASE 1 1900\n"
            . "1 ASSO @I1@\n2 ROLE OTHER\n3 PHRASE god@@mother\n1 ASSO @I1@\n2 ROLE OTHER\n1 ASSO @I1@\n2 ROLE GODP\n3 PHRASE godmother\n"
            . "1 ASSO @I1@\n2 ROLE _GODCHILD\n"
            . "1 OBJE @X1@\n"
            . "0 @S1@ SOUR\n1 DATA\n2 EVEN birt,dns/can\n2 EVEN BIRT, DEAT\n"
            . "0 @X1@ OBJE\n1 FILE a@@b%20c.jpg\n2 FORM image/jpeg\n1 FILE file:///C:/photos/a.jpg\n2 FORM image/jpeg\n0 TRLR\n", $output);
    }

    #[Test]
    public function structuresSevenHasInAnotherForm(): void
    {
        $output = $this->convert(self::HEAD . "1 FILE tree.ged\n1 LANG Hmong\n0 @I1@ INDI\n"
            . "1 NAME Kim /Minjun/\n2 FONE 김 /민준/\n3 TYPE hangul\n2 ROMN Gim /Minjun/\n3 TYPE McCune\n"
            . "1 RESI\n2 PLAC Kyiv\n3 ROMN Kyiv\n0 TRLR\n");

        self::assertSame("\u{FEFF}0 HEAD\n1 SOUR Korin\n1 DATE 1 JAN 2020\n1 GEDC\n2 VERS 7.0\n1 LANG und\n2 _PHRASE Hmong\n"
            . "1 SCHMA\n2 TAG _PHRASE https://gedcom.io/terms/v7/PHRASE\n0 @I1@ INDI\n"
            . "1 NAME Kim /Minjun/\n2 TRAN 김 /민준/\n3 LANG ko-Hang\n2 TRAN Gim /Minjun/\n3 LANG und-Latn\n3 _TYPE McCune\n"
            . "1 RESI\n2 PLAC Kyiv\n3 TRAN Kyiv\n4 LANG und-Latn\n0 TRLR\n", $output);
    }

    #[Test]
    public function translationTypesAreReadForTheirKind(): void
    {
        $output = $this->convert(self::HEAD . "0 @I1@ INDI\n1 NAME A\n2 FONE a\n3 TYPE\n2 FONE b\n3 TYPE romaji\n2 ROMN c\n3 TYPE Kana\n0 TRLR\n");

        self::assertSame(self::HEAD_70 . "0 @I1@ INDI\n1 NAME A\n2 TRAN a\n3 LANG und\n2 TRAN b\n3 LANG und\n3 _TYPE romaji\n"
            . "2 TRAN c\n3 LANG und-Latn\n3 _TYPE Kana\n0 TRLR\n", $output);
    }

    #[Test]
    public function schemaIsAddedToAnExistingOneOrANewHead(): void
    {
        self::assertSame(
            self::HEAD_70 . "1 LANG und\n2 _PHRASE Hmong\n1 SCHMA\n2 TAG _X http://x\n2 TAG _PHRASE https://gedcom.io/terms/v7/PHRASE\n0 TRLR\n",
            $this->convert(self::HEAD . "1 LANG Hmong\n1 SCHMA\n2 TAG _X http://x\n0 TRLR\n"),
        );
        self::assertSame(
            "\u{FEFF}0 HEAD\n1 SCHMA\n2 TAG _PHRASE https://gedcom.io/terms/v7/PHRASE\n1 GEDC\n2 VERS 7.0\n0 @U1@ SUBM\n1 NAME Me\n1 LANG und\n2 _PHRASE Hmong\n0 TRLR\n",
            $this->convert("0 @U1@ SUBM\n1 NAME Me\n1 LANG Hmong\n0 TRLR\n"),
        );
    }

    #[Test]
    public function schemaLeavesOutTagsTheInputAlreadyHas(): void
    {
        $output = $this->convert(self::HEAD . "1 LANG Hmong\n0 @I1@ INDI\n1 _PHRASE own meaning\n0 TRLR\n");

        self::assertSame(self::HEAD_70 . "1 LANG und\n2 _PHRASE Hmong\n0 @I1@ INDI\n1 _PHRASE own meaning\n0 TRLR\n", $output);
    }

    #[Test]
    public function extensionsNamedAfterSevenStructuresGetTheirTags(): void
    {
        $output = $this->convert(self::HEAD . "0 @I1@ INDI\n1 SEX U\n2 _SEX X\n1 _NO MARR\n1 BIRT\n2 _DATE 1900\n2 DATE 1901\n3 _PHRASE then\n2 _SDATE 1901\n"
            . "1 _FAMC @VOID@\n1 _X\n2 _NO BIRT\n1 _APID 1,7602::1\n0 TRLR\n");

        self::assertSame(self::HEAD_70 . "0 @I1@ INDI\n1 SEX X\n1 NO MARR\n1 BIRT\n2 _DATE 1900\n2 DATE 1901\n3 PHRASE then\n2 SDATE 1901\n"
            . "1 FAMC @VOID@\n1 _X\n2 _NO BIRT\n1 _APID 1,7602::1\n0 TRLR\n", $output);
    }

    #[Test]
    public function inlineMediaKeepsItsFiveFiveOneMeaning(): void
    {
        $output = $this->convert(self::HEAD . "0 @I1@ INDI\n1 OBJE\n2 FILE a.wav\n3 FORM wav\n4 MEDI Audio\n0 TRLR\n");

        self::assertSame(self::HEAD_70 . "0 @I1@ INDI\n1 OBJE @X1@\n"
            . "0 @X1@ OBJE\n1 FILE a.wav\n2 FORM audio/wav\n3 MEDI AUDIO\n0 TRLR\n", $output);
    }

    #[Test]
    public function theOutputIsValidSevenAndTheInputIsNotChanged(): void
    {
        $input = "0 HEAD\r\n1 SOUR Korin\r\n1 SUBM @u@\r\n1 GEDC\r\n2 VERS 5.5.1\r\n2 FORM LINEAGE-LINKED\r\n1 CHAR UTF-8\r\n"
            . "0 @u@ SUBM\r\n1 NAME Korin\r\n0 @i 1@ INDI\r\n1 NAME Anna /Koval/\r\n1 NOTE @n@\r\n0 @n@ NOTE a@@b\r\n0 TRLR\r\n";
        $doc = Gedcom::parse($input);

        $result = Gedcom::convert($doc, Version::V7_0);

        self::assertTrue($result->losses()->isEmpty());
        self::assertSame([], array_map(strval(...), array_filter(
            Gedcom::validate($result->document())->diagnostics(),
            static fn(Diagnostic $d): bool => $d->severity === Severity::Error,
        )));
        self::assertSame("\u{FEFF}0 HEAD\r\n1 SOUR Korin\r\n1 SUBM @X1@\r\n1 GEDC\r\n2 VERS 7.0\r\n"
            . "0 @X1@ SUBM\r\n1 NAME Korin\r\n0 @X2@ INDI\r\n1 NAME Anna /Koval/\r\n1 SNOTE @X3@\r\n0 @X3@ SNOTE a@b\r\n0 TRLR\r\n", Gedcom::serialize($result->document()));
        self::assertSame($input, Gedcom::serialize($doc));
        self::assertNotNull($doc->record('@i 1@'));

        $result->document()->addRecord('SNOTE', '@N2@', 'Later');
        self::assertStringNotContainsString('Later', $result->output(), 'the output is fixed when the conversion ends');
    }

    private function convert(string $gedcom): string
    {
        return Gedcom::serialize(Gedcom::convert(Gedcom::parse($gedcom), Version::V7_0)->document());
    }
}

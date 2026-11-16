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
            . "1 BIRT\n2 DATE @#DJULIAN@ 1 JAN 1700\n"
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
            . "0 @X1@ OBJE\n1 FILE a.jpg\n2 FORM jpg\n1 _MARK x\n"
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

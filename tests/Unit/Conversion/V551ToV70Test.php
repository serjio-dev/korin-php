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

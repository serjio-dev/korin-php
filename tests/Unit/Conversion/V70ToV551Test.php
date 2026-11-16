<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V70ToV551Test extends TestCase
{
    private const HEAD = "0 HEAD\n1 SOUR Korin\n1 DATE 1 JAN 2020\n1 GEDC\n2 VERS 7.0\n";
    private const HEAD_551 = "0 HEAD\n1 SOUR Korin\n1 DATE 1 JAN 2020\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n";

    #[Test]
    public function headGetsGedcFormAndChar(): void
    {
        self::assertSame(self::HEAD_551 . "0 TRLR\n", $this->convert("\u{FEFF}" . self::HEAD . "0 TRLR\n"));
        self::assertSame("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 NOTE x\n1 CHAR UTF-8\n0 TRLR\n", $this->convert("0 HEAD\n1 GEDC\n2 VERS 7.0\n1 NOTE x\n0 TRLR\n"));
    }

    #[Test]
    public function atSignsFollowTheFiveFiveOneRulesAndLongLinesAreSplit(): void
    {
        $long = str_repeat('word ', 60) . 'end';
        $output = $this->convert(self::HEAD . "0 @I1@ INDI\n1 NAME Anna @ home /Koval/\n2 NOTE @@ first, @#DJULIAN@ text\n3 CONT second @ line\n"
            . "0 @N1@ SNOTE {$long}\n0 TRLR\n");

        [$before, $note] = explode('0 @N1@ NOTE ', $output);
        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 NAME Anna @@ home /Koval/\n2 NOTE @@ first, @#DJULIAN@ text\n3 CONT second @@ line\n", $before);
        $lines = explode("\n", '0 @N1@ NOTE ' . $note);
        self::assertSame('1 CONC ', substr($lines[1], 0, 7));
        self::assertLessThanOrEqual(255, max(array_map(strlen(...), $lines)));
        self::assertSame($long, substr($lines[0], 12) . substr($lines[1], 7));
    }

    #[Test]
    public function sharedNotesAreNoteRecordsAndSubmissionsComeBack(): void
    {
        $output = $this->convert(self::HEAD . "1 SUBM @U1@\n1 _SUBN\n2 FAMF Family file\n2 TEMP SLAKE\n"
            . "0 @I1@ INDI\n1 SNOTE @N1@\n1 NOTE Inline\n1 _X\n2 SNOTE @N1@\n0 @N1@ SNOTE Shared\n0 @U1@ SUBM\n1 NAME Me\n0 TRLR\n");

        self::assertSame(substr(self::HEAD_551, 0, -13) . "1 SUBM @U1@\n1 SUBN @X1@\n1 CHAR UTF-8\n"
            . "0 @I1@ INDI\n1 NOTE @N1@\n1 NOTE Inline\n1 _X\n2 SNOTE @N1@\n0 @N1@ NOTE Shared\n0 @U1@ SUBM\n1 NAME Me\n"
            . "0 @X1@ SUBN\n1 FAMF Family file\n1 TEMP SLAKE\n0 TRLR\n", $output);
    }

    #[Test]
    public function identifiersGoBackToTheirFiveFiveOneTags(): void
    {
        $result = Gedcom::convert(Gedcom::parse("0 HEAD\n1 SOUR My App\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 UID 1234-abcd\n"
            . "1 EXID 42\n2 TYPE https://gedcom.io/terms/v7/AFN\n1 EXID abc\n2 TYPE https://gedcom.io/terms/v7/RFN#Zeta%20DB\n"
            . "1 EXID x\n2 TYPE https://gedcom.io/terms/v7/RFN\n1 EXID 9\n2 TYPE https://gedcom.io/terms/v7/RIN#My%20App\n"
            . "1 EXID 8\n2 TYPE https://gedcom.io/terms/v7/RIN#Other\n1 EXID a:b\n2 TYPE https://gedcom.io/terms/v7/RFN\n"
            . "1 EXID 77\n2 TYPE https://www.familysearch.org/ark:/61903/4:1:\n"
            . "0 @F1@ FAM\n1 EXID 3\n2 TYPE https://gedcom.io/terms/v7/AFN\n0 TRLR\n"), Version::V5_5_1);

        self::assertSame("0 HEAD\n1 SOUR My App\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @I1@ INDI\n1 _UID 1234-abcd\n"
            . "1 AFN 42\n1 RFN abc:Zeta DB\n1 RFN x\n1 RIN 9\n"
            . "1 _EXID 8\n2 TYPE https://gedcom.io/terms/v7/RIN#Other\n1 _EXID a:b\n2 TYPE https://gedcom.io/terms/v7/RFN\n"
            . "1 _EXID 77\n2 TYPE https://www.familysearch.org/ark:/61903/4:1:\n"
            . "0 @F1@ FAM\n1 _EXID 3\n2 TYPE https://gedcom.io/terms/v7/AFN\n0 TRLR\n", Gedcom::serialize($result->document()));
        self::assertSame(['KL2001 warning line 15 (@I1@/EXID[4])', 'KL2001 warning line 17 (@I1@/EXID[5])', 'KL2001 warning line 19 (@I1@/EXID[6])', 'KL2001 warning line 22 (@F1@/EXID)'], $this->where($result->losses()->entries()));
    }

    #[Test]
    public function pointersToVoidBecomeExtensions(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n1 FAMC @VOID@\n2 PHRASE Unknown parents\n1 _X\n2 FAMC @VOID@\n1 ASSO @VOID@\n2 ROLE FRIEND\n0 TRLR\n";

        $result = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1);
        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 _FAMC @VOID@\n2 PHRASE Unknown parents\n1 _X\n2 FAMC @VOID@\n1 _ASSO @VOID@\n2 ROLE FRIEND\n0 TRLR\n", Gedcom::serialize($result->document()));
        self::assertSame(['KL4001 warning line 7 (@I1@/FAMC)', 'KL4001 warning line 11 (@I1@/ASSO)'], $this->where($result->losses()->entries()));

        $dropped = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::DropWithReport));
        self::assertSame(self::HEAD_551 . "0 @I1@ INDI\n1 _X\n2 FAMC @VOID@\n0 TRLR\n", Gedcom::serialize($dropped->document()));
        self::assertSame('dropped', $dropped->losses()->entries()[0]->storedAs->value);

        $this->expectException(ConversionException::class);
        Gedcom::convert(Gedcom::parse($input), Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::Fail));
    }

    #[Test]
    public function structuresFiveFiveOneDoesNotHaveBecomeExtensions(): void
    {
        $input = "\u{FEFF}" . self::HEAD . "1 SCHMA\n2 TAG _LOC https://example.org/loc\n0 @I1@ INDI\n1 NAME 山田 /太郎/\n2 TRAN Yamada /Taro/\n3 LANG ja-Latn\n"
            . "2 TRAN やまだ /たろう/\n3 LANG und\n3 _TYPE hiragana\n2 TRAN Jamada\n3 LANG uk\n1 SEX X\n1 NO MARR\n2 DATE TO 1900\n"
            . "1 BIRT\n2 DATE ABT 1900\n3 PHRASE around then\n2 ASSO @VOID@\n3 ROLE WITN\n1 CREA\n2 DATE 1 JAN 2020\n0 @N1@ SNOTE Text\n1 MIME text/plain\n0 TRLR\n";

        $down = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1);
        self::assertSame(substr(self::HEAD_551, 0, -13) . "1 _SCHMA\n2 TAG _LOC https://example.org/loc\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME 山田 /太郎/\n2 ROMN Yamada /Taro/\n3 TYPE romaji\n"
            . "2 FONE やまだ /たろう/\n3 TYPE hiragana\n2 _TRAN Jamada\n3 LANG uk\n1 SEX U\n2 _SEX X\n1 _NO MARR\n2 DATE TO 1900\n"
            . "1 BIRT\n2 DATE ABT 1900\n3 _PHRASE around then\n2 _ASSO @VOID@\n3 ROLE WITN\n1 _CREA\n2 DATE 1 JAN 2020\n0 @N1@ NOTE Text\n1 _MIME text/plain\n0 TRLR\n", $down->output());
        self::assertSame(['KL2001 warning line 6 (HEAD/SCHMA)', 'KL2001 warning line 15 (@I1@/NAME/TRAN[2])', 'KL3002 warning line 17 (@I1@/SEX)', 'KL2001 warning line 18 (@I1@/NO)',
            'KL2001 warning line 22 (@I1@/BIRT/DATE/PHRASE)', 'KL4001 warning line 23 (@I1@/BIRT/ASSO)', 'KL2001 warning line 25 (@I1@/CREA)', 'KL2001 warning line 28 (@N1@/MIME)'], $this->where($down->losses()->entries()));
        self::assertSame($input, Gedcom::convert($down->document(), Version::V7_0)->output());

        $dropped = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::DropWithReport));
        self::assertStringContainsString("1 SEX U\n1 BIRT\n2 DATE ABT 1900\n0 @N1@ NOTE Text\n0 TRLR\n", $dropped->output());
    }

    #[Test]
    public function upDownUpKeepsWhatTheseRulesConvert(): void
    {
        $input = "\u{FEFF}" . self::HEAD . "1 _SUBN\n2 FAMF Family file\n0 @I1@ INDI\n1 NAME Anna @ home\n1 SNOTE @N1@\n1 UID 1234\n"
            . "1 EXID 42\n2 TYPE https://gedcom.io/terms/v7/AFN\n0 @N1@ SNOTE @@ Shared\n0 TRLR\n";

        $down = Gedcom::convert(Gedcom::parse($input), Version::V5_5_1);
        self::assertSame($input, Gedcom::convert($down->document(), Version::V7_0)->output());
    }

    /**
     * @param list<Loss> $losses
     *
     * @return list<string>
     */
    private function where(array $losses): array
    {
        return array_map(static fn(Loss $loss): string => explode(',', (string) $loss)[0], $losses);
    }

    private function convert(string $gedcom): string
    {
        return Gedcom::serialize(Gedcom::convert(Gedcom::parse($gedcom), Version::V5_5_1)->document());
    }
}

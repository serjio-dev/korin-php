<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\ConversionResult;
use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V551ToV555Test extends TestCase
{
    private const HEAD = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME Me\n";
    private const HEAD_555 = "\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME Me\n";

    #[Test]
    public function theOutputHasABomAndTheVersionOfTheForm(): void
    {
        $result = $this->down(self::HEAD . "0 TRLR\n");

        self::assertSame(self::HEAD_555 . "0 TRLR\n", $result->output());
        self::assertSame([], $result->losses()->entries());
        self::assertSame([], Gedcom::validate($result->document())->diagnostics());
    }

    #[Test]
    public function aMediaFormatFiveFiveFiveDoesNotListStays(): void
    {
        $result = $this->down(self::HEAD . "0 @I1@ INDI\n1 OBJE\n2 FILE a.ole\n3 FORM ole\n0 TRLR\n");

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n1 OBJE @X1@\n0 @X1@ OBJE\n1 FILE a.ole\n2 FORM ole\n0 TRLR\n", $result->output());
        self::assertSame([], $result->losses()->entries());
        self::assertSame(['KV3006'], array_map(static fn(Diagnostic $d): string => $d->code, Gedcom::validate($result->document())->errors()));
    }

    #[Test]
    public function inlineSourcesAndMediaBecomeRecords(): void
    {
        $result = $this->down(self::HEAD . "0 @I1@ INDI\n1 BIRT\n2 DATE 1900\n2 SOUR Family Bible\n3 CONT page 2\n3 TEXT Born at home\n3 QUAY 2\n"
            . "2 OBJE\n3 FILE a.jpg\n4 FORM jpg\n5 MEDI photo\n3 TITL Portrait\n0 TRLR\n");

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n1 BIRT\n2 DATE 1900\n2 SOUR @X1@\n3 DATA\n4 TEXT Born at home\n3 QUAY 2\n2 OBJE @X2@\n"
            . "0 @X1@ SOUR\n1 TITL Family Bible\n2 CONT page 2\n0 @X2@ OBJE\n1 FILE a.jpg\n2 FORM jpg\n3 TYPE photo\n2 TITL Portrait\n0 TRLR\n", $result->output());
        self::assertSame([], $result->losses()->entries());
        self::assertSame([], Gedcom::validate($result->document())->diagnostics());
    }

    #[Test]
    public function addressesKeepTheirLinesInAdrOrUnderAddr(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n"
            . "1 RESI\n2 ADDR 12 Main St\n3 CONT apt. 4\n3 ADR1 12 Main St\n3 CITY Kyiv\n"
            . "1 RESI\n2 ADDR Kyiv, 01001, Ukraine\n3 CITY Kyiv\n3 POST 01001\n3 CTRY Ukraine\n"
            . "1 RESI\n2 ADDR 1 Rynok Sq\n3 ADR1 Rynok Square 1\n0 TRLR\n";
        $result = $this->down($input);

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n"
            . "1 RESI\n2 ADDR\n3 ADR1 12 Main St\n3 ADR2 apt. 4\n3 CITY Kyiv\n"
            . "1 RESI\n2 ADDR\n3 CITY Kyiv\n3 POST 01001\n3 CTRY Ukraine\n"
            . "1 RESI\n2 ADDR\n3 _ADDR 1 Rynok Sq\n3 ADR1 Rynok Square 1\n0 TRLR\n", $result->output());
        self::assertSame(['KL3001 warning line 22 (@I1@/RESI[2]/ADDR), extension'], $this->where($result));
        self::assertSame(str_replace("3 CITY Kyiv\n1 RESI", "3 ADR2 apt. 4\n3 CITY Kyiv\n1 RESI", $input), Gedcom::convert($result->document(), Version::V5_5_1)->output());
    }

    #[Test]
    public function structuresWithNeitherAValueNorSubstructuresBecomeExtensions(): void
    {
        $input = self::HEAD . "0 @I1@ INDI\n1 BIRT Y\n1 BURI\n1 DEAT Y\n1 CHR\n2 DATE 1900\n1 NOTE\n0 @F1@ FAM\n1 MARR Y\n0 TRLR\n";
        $result = $this->down($input);

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n1 _BIRT Y\n1 _BURI\n1 DEAT Y\n1 CHR\n2 DATE 1900\n1 _NOTE\n0 @F1@ FAM\n1 MARR Y\n0 TRLR\n", $result->output());
        self::assertSame(['KL3001 warning line 11 (@I1@/BIRT)', 'KL3001 warning line 12 (@I1@/BURI)', 'KL3001 warning line 16 (@I1@/NOTE)'], $this->where($result));
        self::assertSame($input, Gedcom::convert($result->document(), Version::V5_5_1)->output());
    }

    #[Test]
    public function valuesFollowTheStricterGrammars(): void
    {
        $result = $this->down(self::HEAD . "0 @I1@ INDI\n1 DEAT\n2 DATE BET 05 JAN 50 AND 1 FEB 1950\n2 AGE >01y 0m 3d\n2 PLAC Kyiv,Ukraine\n"
            . "1 BURI\n2 AGE 0y\n1 CHR\n2 AGE 42m\n1 ADOP\n2 AGE 1y 13m 2d\n1 BAPM\n2 AGE 1Y 12M\n1 CONF\n2 AGE 2y 11m\n1 CHAN\n2 DATE 1 JAN 2000\n3 TIME 09:05:00.5\n0 TRLR\n");

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n1 DEAT\n2 DATE BET 5 JAN 050 AND 1 FEB 1950\n2 AGE > 1y 3d\n2 PLAC Kyiv, Ukraine\n"
            . "1 BURI\n2 _AGE 0y\n1 CHR\n2 AGE 3y 6m\n1 ADOP\n2 AGE 2y 1m 2d\n1 BAPM\n2 AGE 2y\n1 CONF\n2 AGE 2y 11m\n1 CHAN\n2 DATE 1 JAN 2000\n3 TIME 9:05:00.50\n0 TRLR\n", $result->output());
        self::assertSame(['KL3001 warning line 16 (@I1@/BURI/AGE)'], $this->where($result));
    }

    #[Test]
    public function theSubmitterFollowsTheHeaderAndOtherSubmittersBecomeExtensions(): void
    {
        $input = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U2@\n1 SUBN @N1@\n"
            . "0 @U1@ SUBM\n1 NAME Other\n0 @I1@ INDI\n1 SEX M\n0 @U2@ SUBM\n1 NAME Me\n0 @N1@ SUBN\n1 FAMF Family file\n0 TRLR\n";
        $result = $this->down($input);

        self::assertSame("\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U2@\n1 _SUBN\n2 FAMF Family file\n"
            . "0 @U2@ SUBM\n1 NAME Me\n0 @U1@ _SUBM\n1 NAME Other\n0 @I1@ INDI\n1 SEX M\n0 TRLR\n", $result->output());
        self::assertSame(['KL2002 warning line 9 (@U1@)'], $this->where($result));
        self::assertSame([], Gedcom::validate($result->document())->diagnostics());

        $up = Gedcom::convert($result->document(), Version::V5_5_1)->output();
        self::assertSame("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SOUR Korin\n1 SUBM @U2@\n1 SUBN @X1@\n"
            . "0 @U2@ SUBM\n1 NAME Me\n0 @U1@ SUBM\n1 NAME Other\n0 @I1@ INDI\n1 SEX M\n0 @X1@ SUBN\n1 FAMF Family file\n0 TRLR\n", $up);
    }

    #[Test]
    public function aMissingHeadSubmPointsToTheFirstSubmitter(): void
    {
        $result = $this->down("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SOUR Korin\n0 @I1@ INDI\n0 @U1@ SUBM\n1 NAME Me\n0 TRLR\n");

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n0 TRLR\n", $result->output());
    }

    #[Test]
    public function sexXOfSevenIsSexXAgain(): void
    {
        $input = "\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 7.0\n1 SOUR Korin\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME Me\n0 @I1@ INDI\n1 SEX X\n0 TRLR\n";
        $down = Gedcom::convert(Gedcom::parse($input), Version::V5_5_5);

        // HEAD.CHAR, which 7.0 does not have, is added last.
        self::assertSame("\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 SOUR Korin\n1 SUBM @U1@\n1 CHAR UTF-8\n0 @U1@ SUBM\n1 NAME Me\n0 @I1@ INDI\n1 SEX X\n0 TRLR\n", $down->output());
        self::assertSame($input, Gedcom::convert($down->document(), Version::V7_0)->output());
    }

    #[Test]
    public function dropWithReportRemovesWhatFiveFiveFiveDoesNotHave(): void
    {
        $result = Gedcom::convert(Gedcom::parse(self::HEAD . "0 @I1@ INDI\n1 RESN privacy\n1 BURI\n1 RESI\n2 ADDR Line 1\n3 CONT Line 2\n3 ADR1 Other\n0 TRLR\n"), Version::V5_5_5, (new ConvertOptions())->withPolicy(LossPolicy::DropWithReport));

        self::assertSame(self::HEAD_555 . "0 @I1@ INDI\n1 RESI\n2 ADDR\n3 ADR1 Other\n0 TRLR\n", $result->output());
        self::assertSame(['KL2001 warning line 11 (@I1@/RESN), dropped', 'KL3001 warning line 12 (@I1@/BURI), dropped', 'KL3001 warning line 14 (@I1@/RESI/ADDR), dropped'], $this->where($result, true));
    }

    private function down(string $gedcom): ConversionResult
    {
        return Gedcom::convert(Gedcom::parse($gedcom), Version::V5_5_5);
    }

    /** @return list<string> */
    private function where(ConversionResult $result, bool $storedAs = false): array
    {
        return array_map(static function (Loss $loss) use ($storedAs): string {
            $parts = explode(',', (string) $loss);

            return $storedAs || str_contains($parts[0], 'ADDR') ? $parts[0] . ', ' . $loss->storedAs->value : $parts[0];
        }, $result->losses()->entries());
    }
}

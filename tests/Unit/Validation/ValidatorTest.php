<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Validation\ValidationOptions;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private const HEAD_70 = "0 HEAD\n1 GEDC\n2 VERS 7.0\n";

    #[Test]
    public function validDocumentHasNoDiagnostics(): void
    {
        $report = Gedcom::validate(Gedcom::parse(self::HEAD_70 . "0 @I1@ INDI\n1 NAME Анна /Коваль/\n2 GIVN Анна\n1 BIRT\n2 DATE 1921\n1 _UID 1234\n2 _ANY deep\n0 TRLR\n"));

        self::assertTrue($report->isValid());
        self::assertSame([], $report->diagnostics());
    }

    #[Test]
    public function tagNotAllowedHere(): void
    {
        $report = Gedcom::validate(Gedcom::parse(self::HEAD_70 . "0 @I1@ INDI\n1 DATE 1921\n2 TIME 10:00\n1 BIRT\n2 GIVN x\n0 @X1@ PLAC Somewhere\n0 TRLR\n"));

        self::assertSame([
            'KV2001 error line 5 (@I1@/DATE): DATE is not allowed in INDI',
            'KV2001 error line 8 (@I1@/BIRT/GIVN): GIVN is not allowed in BIRT',
            'KV2001 error line 9 (@X1@): PLAC is not allowed as a record',
        ], $this->lines($report->diagnostics()));
        self::assertSame('https://gedcom.io/terms/v7/record-INDI', $report->diagnostics()[0]->reference);
        self::assertFalse($report->isValid());
    }

    #[Test]
    public function cardinalityIsChecked(): void
    {
        $report = Gedcom::validate(Gedcom::parse(self::HEAD_70 . "1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 SEX F\n1 SEX M\n1 SEX U\n0 TRLR\n"));

        // Only the first extra occurrence is reported.
        self::assertSame([
            'KV2002 error line 4 (HEAD/GEDC[1]): GEDC occurs more than 1 time(s) in HEAD',
            'KV2002 error line 8 (@I1@/SEX[1]): SEX occurs more than 1 time(s) in INDI',
        ], $this->lines($report->diagnostics()));
    }

    #[Test]
    public function requiredSubstructuresAreChecked(): void
    {
        $doc = Gedcom::parse("0 HEAD\n0 @F1@ FAM\n1 MARR\n2 HUSB\n2 WIFE\n3 AGE 25y\n0 TRLR\n", (new ReadOptions())->withVersion(Version::V7_0));

        self::assertSame([
            'KV2003 error line 1 (HEAD): HEAD requires GEDC',
            'KV2003 error line 4 (@F1@/MARR/HUSB): HUSB requires AGE',
        ], $this->lines(Gedcom::validate($doc)->errors()));
    }

    #[Test]
    public function optionsDisableAndEscalateCodes(): void
    {
        $doc = Gedcom::parse(self::HEAD_70 . "0 @I1@ INDI\n1 DATE 1921\n\n0 TRLR\n");

        $report = Gedcom::validate($doc, (new ValidationOptions())->withDisabled('KV2001')->withSeverity('KV1001', Severity::Error));

        self::assertSame(['KV1001 error line 6: Empty line'], $this->lines($report->diagnostics()));
        self::assertCount(1, $report);
        self::assertSame('[{"code":"KV1001","severity":"error","message":"Empty line","line":6,"path":null,"reference":null}]', json_encode($report));
    }

    #[Test]
    public function versionsWithoutAProfileAreReportedAsNotChecked(): void
    {
        $report = Gedcom::validate(Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5\n0 TRLR\n"));

        self::assertSame('KV2000', $report->diagnostics()[0]->code ?? null);
        self::assertTrue($report->isValid());
    }

    #[Test]
    public function gedcom551IsCheckedAgainstItsGrammar(): void
    {
        $doc = Gedcom::parse(
            "0 HEAD\n1 SOUR KORIN\n1 SUBM @U1@\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n"
            . "0 @U1@ SUBM\n1 NAME Анна\n0 @N1@ NOTE Shared note\n"
            . "0 @I1@ INDI\n1 NAME Анна /Коваль/\n2 SURN Коваль\n1 BIRT\n2 DATE 1921\n2 PLAC Київ\n3 MAP\n4 LATI N50.45\n"
            . "2 FAMC @F1@\n1 DEAT Y\n2 FAMC @F1@\n1 NOTE @N1@\n1 NOTE Inline text\n2 CONT more\n1 SOUR Inline source\n"
            . "1 OBJE\n2 FILE photo.jpg\n3 FORM jpg\n1 FAMS @N1@\n1 SEX F\n1 SEX M\n0 @F1@ FAM\n1 CHIL @I1@\n1 MARR\n2 WIFE\n3 AGE 25\n0 TRLR\n",
        );

        self::assertSame([
            'KV2003 error line 17 (@I1@/BIRT/PLAC/MAP): MAP requires LONG',
            'KV2001 error line 21 (@I1@/DEAT/FAMC): FAMC is not allowed in DEAT',
            'KV4003 error line 29 (@I1@/FAMS): FAMS must point to a FAM record, not NOTE',
            'KV2002 error line 31 (@I1@/SEX[1]): SEX occurs more than 1 time(s) in INDI',
            'KV4004 warning line 33 (@F1@/CHIL): @I1@ has no FAMC pointing back to @F1@',
        ], $this->lines(Gedcom::validate($doc)->diagnostics()));
        self::assertSame(
            'https://gedcom.io/specifications/ged551.pdf#PLACE_STRUCTURE/PLAC/MAP',
            Gedcom::validate($doc)->errors()[0]->reference,
        );
    }

    #[Test]
    public function validationDoesNotChangeTheDocument(): void
    {
        $bytes = self::HEAD_70 . "0 @I1@ INDI\n1 DATE 1921\n0 TRLR\n";
        $doc = Gedcom::parse($bytes);
        Gedcom::validate($doc);

        self::assertSame($bytes, Gedcom::serialize($doc));
    }

    #[Test]
    public function danglingPointersAndDuplicateXrefs(): void
    {
        $report = Gedcom::validate(Gedcom::parse(self::HEAD_70 . "1 SUBM @VOID@\n0 @I1@ INDI\n1 FAMC @F9@\n1 SNOTE @n1@\n0 @N1@ SNOTE Text\n0 @N1@ SNOTE Again\n0 TRLR\n"));

        self::assertSame([
            'KV4001 error line 6 (@I1@/FAMC): Pointer to a record that does not exist',
            'KV4001 error line 7 (@I1@/SNOTE): Pointer to a record that does not exist',
            'KV4002 error line 9 (@N1@): Xref is already used by the record on line 8',
        ], self::lines($report->diagnostics()));
    }

    #[Test]
    public function voidIsANullPointerOnlyIn7(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 SUBM @VOID@\n0 TRLR\n");
        $report = Gedcom::validate($doc, (new ValidationOptions())->withDisabled('KV2003'));

        self::assertSame(['KV4001 error line 4 (HEAD/SUBM): Pointer to a record that does not exist'], self::lines($report->errors()));
    }

    #[Test]
    public function pointerMustTargetTheRightRecordType(): void
    {
        $report = Gedcom::validate(Gedcom::parse(self::HEAD_70 . "0 @I1@ INDI\n1 FAMC @I2@\n0 @I2@ INDI\n1 ASSO @I1@\n2 ROLE FRIEND\n0 TRLR\n"));

        self::assertSame(['KV4003 error line 5 (@I1@/FAMC): FAMC must point to a FAM record, not INDI'], self::lines($report->diagnostics()));
        self::assertSame('https://gedcom.io/terms/v7/INDI-FAMC', $report->diagnostics()[0]->reference);
    }

    #[Test]
    public function familyLinksMustBeMutual(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n0 @I1@ INDI\n1 FAMS @F1@\n1 FAMC @F2@\n0 @I2@ INDI\n0 @F1@ FAM\n1 HUSB @I1@\n1 CHIL @I2@\n0 @F2@ FAM\n1 WIFE @I2@\n0 TRLR\n");

        self::assertSame([
            'KV4004 warning line 6 (@I1@/FAMC): @F2@ has no CHIL pointing back to @I1@',
            'KV4004 warning line 10 (@F1@/CHIL): @I2@ has no FAMC pointing back to @F1@',
            'KV4004 warning line 12 (@F2@/WIFE): @I2@ has no FAMS pointing back to @F2@',
        ], self::lines(Gedcom::validate($doc)->warnings()));
    }

    /**
     * @param list<Diagnostic> $diagnostics
     *
     * @return list<string>
     */
    private function lines(array $diagnostics): array
    {
        return array_map(static fn(Diagnostic $d): string => (string) $d, $diagnostics);
    }
}

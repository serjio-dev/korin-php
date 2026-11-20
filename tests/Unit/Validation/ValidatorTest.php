<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;
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
    public function fiveXFormsByPointerAndInlineAreCheckedEachByItsOwn(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 SOUR X\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME A\n0 @S1@ SOUR\n0 @O1@ OBJE\n1 FILE a.jpg\n2 FORM jpg\n"
            . "0 @I1@ INDI\n1 SOUR @S1@\n2 TEXT x\n2 PAGE 4\n1 SOUR Told\n2 PAGE 4\n2 TEXT x\n1 OBJE @O1@\n2 TITL x\n1 OBJE\n2 TITL x\n0 TRLR\n");

        self::assertSame([
            'KV2001 error line 16 (@I1@/SOUR[0]/TEXT): TEXT is not allowed in SOUR with a pointer',
            'KV2001 error line 19 (@I1@/SOUR[1]/PAGE): PAGE is not allowed in SOUR without a pointer',
            'KV2001 error line 22 (@I1@/OBJE[0]/TITL): TITL is not allowed in OBJE with a pointer',
            'KV2003 error line 23 (@I1@/OBJE[1]): OBJE without a pointer requires FILE',
        ], $this->lines(Gedcom::validate($doc)->diagnostics()));
    }

    #[Test]
    public function aSevenHeadHasANoteOrASharedNote(): void
    {
        $doc = Gedcom::parse(self::HEAD_70 . "1 NOTE a\n1 SNOTE @N1@\n0 @N1@ SNOTE x\n0 @I1@ INDI\n1 NOTE a\n1 SNOTE @N1@\n0 TRLR\n");

        // INDI allows any number of either.
        self::assertSame(['KV2002 error line 5 (HEAD/SNOTE): HEAD allows one of NOTE and SNOTE'], $this->lines(Gedcom::validate($doc)->diagnostics()));
        // Reported at the later one, whichever comes first.
        $doc = Gedcom::parse(self::HEAD_70 . "1 SNOTE @N1@\n1 NOTE a\n0 @N1@ SNOTE x\n0 TRLR\n");
        self::assertSame(['KV2002 error line 5 (HEAD/NOTE): HEAD allows one of NOTE and SNOTE'], $this->lines(Gedcom::validate($doc)->diagnostics()));
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
    public function gedcom555IsCheckedAgainstItsStricterGrammar(): void
    {
        $doc = Gedcom::parse(
            "\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SOUR KORIN\n2 VERS 0.2\n"
            . "0 @U1@ SUBM\n1 NAME Anna\n0 @I1@ INDI\n1 NAME Anna /Koval/\n1 SEX X\n1 BIRT Y\n1 DEAT\n1 CHR Y\n1 IDNO 12345\n"
            . "1 RESI\n2 DATE 44 BCE\n2 AGE <1y\n1 OCCU " . str_repeat('a', 91) . "\n1 FACT Woodworking\n2 TYPE Skills\n2 DATE 23 AUG\n0 TRLR\n",
        );

        self::assertSame([
            'KV3002 error line 14 (@I1@/BIRT): BIRT must not have a value',
            'KV3007 error line 15 (@I1@/DEAT): DEAT has neither a value nor substructures',
            'KV2003 error line 17 (@I1@/IDNO): IDNO requires TYPE',
            'KV3005 error line 19 (@I1@/RESI/DATE): DATE must be a date, e.g. "6 DEC 1907", "ABT @#DJULIAN@ 1650" or "(date phrase)"',
            'KV3005 error line 20 (@I1@/RESI/AGE): AGE must be an age, e.g. "35y 3m", "< 1y" or "CHILD"',
            'KV3010 error line 21 (@I1@/OCCU): OCCU is longer than 90 characters (91)',
        ], $this->lines(Gedcom::validate($doc)->diagnostics()));
    }

    #[Test]
    public function gedcom555RequiresASubmitterRecord(): void
    {
        $report = Gedcom::validate(Gedcom::parse("\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SOUR KORIN\n0 TRLR\n"));

        self::assertSame(['KV2003 error: The file requires a SUBM record'], $this->lines($report->diagnostics()));
        self::assertSame('https://www.gedcom.org/specs/GEDCOM555.zip#SUBMITTER_RECORD/SUBM', $report->errors()[0]->reference);
    }

    #[Test]
    public function gedcom555FileStartsWithAByteOrderMarkAndTheSubmitter(): void
    {
        $head = "0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SOUR KORIN\n";
        $submitter = "0 @U1@ SUBM\n1 NAME Анна\n";
        $note = "0 @N1@ NOTE Shared note\n";

        self::assertSame([], Gedcom::validate(Gedcom::parse("\u{FEFF}" . $head . $submitter . $note . "0 TRLR\n"))->diagnostics());
        self::assertSame(
            ['KV1104 warning: A GEDCOM 5.5.5 file must start with a byte order mark'],
            $this->lines(Gedcom::validate(Gedcom::parse($head . $submitter . $note . "0 TRLR\n"))->diagnostics()),
        );

        $report = Gedcom::validate(Gedcom::parse("\u{FEFF}" . $head . $note . $submitter . "0 TRLR\n"));
        self::assertSame(['KV2004 error line 9 (@U1@): The SUBM record must directly follow HEAD'], $this->lines($report->diagnostics()));
        self::assertSame('https://www.gedcom.org/specs/GEDCOM555.zip#SUBMITTER_RECORD/SUBM', $report->errors()[0]->reference);

        // Extension records are not checked, so they do not stand between HEAD and SUBM.
        self::assertSame([], Gedcom::validate(Gedcom::parse("\u{FEFF}" . $head . "0 _PUBLISH yes\n" . $submitter . $note . "0 TRLR\n"))->diagnostics());
    }

    #[Test]
    public function submitterAddedAfterReadingHasNoLine(): void
    {
        $doc = Document::create(Version::V5_5_5);
        $doc->addRecord('NOTE', '@N1@', 'Shared note');
        $doc->addRecord('SUBM', '@U1@')->addChild('NAME', 'Анна');

        self::assertContains('KV2004 error (@U1@): The SUBM record must directly follow HEAD', $this->lines(Gedcom::validate($doc)->diagnostics()));
    }

    #[Test]
    public function onlyGedcom555NeedsAByteOrderMarkAndTheSubmitterFirst(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 SOUR KORIN\n1 SUBM @U1@\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @N1@ NOTE Shared note\n0 @U1@ SUBM\n1 NAME Анна\n0 TRLR\n");

        self::assertSame([], Gedcom::validate($doc)->diagnostics());
    }

    #[Test]
    public function newGedcom555DocumentHasAByteOrderMark(): void
    {
        $doc = Document::create(Version::V5_5_5);

        self::assertNotContains('KV1104', array_map(static fn(Diagnostic $d): string => $d->code, Gedcom::validate($doc)->diagnostics()));
    }

    #[Test]
    public function gedcom551IsCheckedAgainstItsGrammar(): void
    {
        $doc = Gedcom::parse(
            "0 HEAD\n1 SOUR KORIN\n1 SUBM @U1@\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n"
            . "0 @U1@ SUBM\n1 NAME Анна\n0 @N1@ NOTE Shared note\n"
            . "0 @I1@ INDI\n1 NAME Анна /Коваль/\n2 SURN Коваль\n1 BIRT\n2 DATE 1921\n2 PLAC Київ\n3 MAP\n4 LATI N50.45\n"
            . "2 FAMC @F1@\n1 DEAT Y\n2 FAMC @F1@\n1 NOTE @N1@\n1 NOTE Inline text\n2 CONT more\n1 SOUR Inline source\n"
            . "1 OBJE\n2 FILE photo.jpg\n3 FORM jpg\n1 FAMS @N1@\n1 SEX F\n1 SEX M\n0 @F1@ FAM\n1 CHIL @I1@\n1 MARR\n2 WIFE\n3 AGE 25y\n0 TRLR\n",
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
    public function gedcom55IsCheckedAgainstItsOwnGrammar(): void
    {
        $doc = Gedcom::parse(
            "0 HEAD\n1 SOUR KORIN\n1 SUBM @U1@\n1 GEDC\n2 VERS 5.5\n2 FORM LINEAGE-LINKED\n1 CHAR ASCII\n0 @U1@ SUBM\n1 NAME Anna\n"
            . "0 @I1@ INDI\n1 NAME Anna /Koval/\n1 SEX U\n1 BIRT\n2 DATE MAR 44 B.C.\n1 ASSO @I1@\n2 RELA Godmother\n1 EVEN Appointed\n"
            . "0 @O1@ OBJE\n1 FORM jpeg\n0 @O2@ OBJE\n1 FORM gif\n1 BLOB\n2 CONT .HM.......k.1..F.jwA\n0 @F1@ FAM\n1 MARR\n2 HUSB\n3 AGE 30y\n0 TRLR\n",
        );

        self::assertSame([
            'KV3006 error line 12 (@I1@/SEX): SEX does not permit "U"; expected one of M, F',
            'KV2003 error line 15 (@I1@/ASSO): ASSO requires TYPE',
            'KV3002 error line 17 (@I1@/EVEN): EVEN must not have a value',
            'KV2003 error line 18 (@O1@): OBJE requires BLOB',
        ], $this->lines(Gedcom::validate($doc)->diagnostics()));
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
    public function danglingPointersOfExtensionsAreWarnings(): void
    {
        $report = Gedcom::validate(Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n0 @I1@ INDI\n1 _FAMC @VOID@\n1 _X\n2 FAMC @F9@\n1 FAMC @F9@\n0 TRLR\n"), (new ValidationOptions())->withDisabled('KV2003'));

        self::assertSame([
            'KV4001 warning line 5 (@I1@/_FAMC): Pointer to a record that does not exist',
            'KV4001 warning line 7 (@I1@/_X/FAMC): Pointer to a record that does not exist',
            'KV4001 error line 8 (@I1@/FAMC): Pointer to a record that does not exist',
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
    public function schemaDefinesExtensionTagsAsStandardTerms(): void
    {
        $head = "0 HEAD\n1 GEDC\n2 VERS 7.0\n1 SCHMA\n2 TAG _USER https://gedcom.io/terms/v7/record-SUBM\n2 TAG _CAL https://gedcom.io/terms/v7/cal-JULIAN\n2 TAG _SPRING https://gedcom.io/terms/v7/month-MAR\n";
        $report = Gedcom::validate(Gedcom::parse($head . "0 @U1@ _USER\n1 NAME Anna\n0 @I1@ INDI\n1 SUBM @U1@\n1 BIRT\n2 DATE _CAL 1 MAR 1700\n1 DEAT\n2 DATE 1 _SPRING 1760\n0 @I2@ INDI\n1 SUBM @I1@\n0 TRLR\n"));

        self::assertSame(['KV4003 error line 17 (@I2@/SUBM): SUBM must point to a SUBM record, not INDI'], self::lines($report->diagnostics()));
    }

    #[Test]
    public function deprecatedStructuresAndValuesAreWarnings(): void
    {
        $doc = Gedcom::parse(self::HEAD_70 . "1 SOUR Korin\n2 DATA Source\n0 @I1@ INDI\n1 EXID 123\n1 EXID 456\n2 TYPE https://example.com\n1 RESI\n2 ADDR Kyiv\n3 ADR1 Kyiv\n1 BAPL\n2 STAT INFANT\n3 DATE 1 JAN 2000\n0 TRLR\n");
        $report = Gedcom::validate($doc);

        self::assertTrue($report->isValid());
        self::assertSame([
            'KV2005 warning line 5 (HEAD/SOUR/DATA): DATA is deprecated; use HEAD.SOUR.NAME instead',
            'KV2005 warning line 7 (@I1@/EXID[0]): EXID without TYPE is deprecated',
            'KV2005 warning line 12 (@I1@/RESI/ADDR/ADR1): ADR1 is deprecated; use the ADDR value instead',
            'KV2005 warning line 14 (@I1@/BAPL/STAT): STAT has the deprecated value "INFANT"; use CHILD instead',
        ], self::lines($report->diagnostics()));
        self::assertSame([], Gedcom::validate($doc, (new ValidationOptions())->withDisabled('KV2005'))->diagnostics());
        self::assertSame([], Gedcom::validate(Gedcom::parse("0 HEAD\n1 SOUR Korin\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME A\n1 ADDR Kyiv\n2 ADR1 Kyiv\n0 TRLR\n"))->diagnostics(), '5.5.1 deprecates nothing');
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

    #[Test]
    public function familyLinksOfTheWrongKindAreNoMirror(): void
    {
        // Families first; HUSB is mirrored only by FAMS, CHIL only by FAMC.
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n0 @F1@ FAM\n1 HUSB @I1@\n1 CHIL @I2@\n1 WIFE @I3@\n0 @I1@ INDI\n1 FAMC @F1@\n0 @I2@ INDI\n1 FAMC @F1@\n0 @I3@ INDI\n1 FAMS @F1@\n1 FAMS @F1@\n0 @F2@ FAM\n1 CHIL @F1@\n0 TRLR\n");

        self::assertSame([
            'KV4004 warning line 5 (@F1@/HUSB): @I1@ has no FAMS pointing back to @F1@',
            'KV4004 warning line 9 (@I1@/FAMC): @F1@ has no CHIL pointing back to @I1@',
        ], self::lines(Gedcom::validate($doc)->warnings()));
        self::assertSame(['KV4003 error line 16 (@F2@/CHIL): CHIL must point to a INDI record, not FAM'], array_values(array_filter(self::lines(Gedcom::validate($doc)->errors()), static fn(string $line): bool => str_starts_with($line, 'KV4'))));
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

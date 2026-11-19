<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Syntax;

use Korin\Gedcom\Exception\DetachedNodeException;
use Korin\Gedcom\Exception\InvalidPathException;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Exception\ReadOnlyException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Model\Family;
use Korin\Gedcom\Model\Individual;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private const FILE = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Anna /Koval/\n1 NAME Hanna /Koval/\n1 NOTE Line one\n2 CONT line @@two\n2 CONC  and more\n1 FAMS @F1@\n0 @F1@ FAM\n1 WIFE @I1@\n0 TRLR\n";

    #[Test]
    public function readsStructureAndJoinsContinuations(): void
    {
        $doc = Gedcom::parse(self::FILE);

        self::assertSame(Version::V5_5_1, $doc->version());
        self::assertSame("Line one\nline @two and more", $doc->find('@I1@/NOTE')?->value());
        self::assertSame('@F1@', $doc->find('@I1@/FAMS')?->pointer());
        self::assertNull($doc->find('@I1@/FAMS')->value());
        self::assertSame(['NAME', 'NAME', 'NOTE', 'FAMS'], array_map(static fn(\Korin\Gedcom\Syntax\Node $n): string => $n->tag(), $doc->record('@I1@')?->children() ?? []));
        self::assertSame('@I1@/NAME[1]', $doc->find('@I1@/NAME[1]')?->path());
        self::assertSame(6, $doc->find('@I1@/NAME[0]')?->line());
        self::assertSame([], $doc->diagnostics());
    }

    #[Test]
    public function ambiguousPathWithoutIndexIsRejected(): void
    {
        $this->expectException(InvalidPathException::class);

        Gedcom::parse(self::FILE)->find('@I1@/NAME');
    }

    #[Test]
    public function unmodifiedDocumentIsWrittenByteForByte(): void
    {
        $bytes = "\xEF\xBB\xBF0 HEAD\r\n1 GEDC\r\n2 VERS 7.0\n\n0 @I1@ INDI\r1 _X  two  spaces \n0 TRLR";

        self::assertSame($bytes, Gedcom::serialize(Gedcom::parse($bytes)));
    }

    #[Test]
    public function editsTouchOnlyChangedLines(): void
    {
        $doc = Gedcom::parse(str_replace("\n", "\r\n", self::FILE));
        $doc->find('@I1@/NOTE')?->setValue("New\nsecond @ line");
        $doc->find('@I1@/NAME[1]')?->remove();
        $doc->record('@I1@')?->addChild('SEX', 'F', position: 1);

        self::assertSame(
            str_replace("\n", "\r\n", "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Anna /Koval/\n1 SEX F\n1 NOTE New\n2 CONT second @@ line\n1 FAMS @F1@\n0 @F1@ FAM\n1 WIFE @I1@\n0 TRLR\n"),
            Gedcom::serialize($doc),
        );
    }

    #[Test]
    public function escapingFollowsTheVersion(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @N1@ SNOTE @@lead and mid@dle\n0 TRLR\n");
        self::assertSame('@lead and mid@dle', $doc->record('@N1@')?->value());

        $doc->record('@N1@')->setValue('@x and @y');
        self::assertStringContainsString("0 @N1@ SNOTE @@x and @y\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function viewsAreLiveAndDetachOnRemoval(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $note = $doc->find('@I1@/NOTE');
        $doc->record('@I1@')?->addChild('SEX', 'F', position: 0);
        self::assertSame('NOTE', $note?->tag(), 'Inserting a sibling does not shift existing views');

        $doc->removeRecord('@I1@');
        self::assertNull($doc->record('@I1@'));
        $this->expectException(DetachedNodeException::class);
        $note->value();
    }

    #[Test]
    public function renameXrefUpdatesPointers(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $doc->renameXref('@F1@', '@FAM1@');

        self::assertSame('@FAM1@', $doc->find('@I1@/FAMS')?->pointer());
        self::assertStringContainsString("0 @FAM1@ FAM\n1 WIFE @I1@\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function setTagAndSetXrefKeepValuesAndLeavePointers(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $note = $doc->find('@I1@/NOTE');
        self::assertNotNull($note);
        $doc->setTag($note->id(), 'SNOTE');
        $doc->setXref($doc->record('@F1@')?->id() ?? 0, '@X1@');

        self::assertNull($doc->record('@F1@'));
        self::assertSame('FAM', $doc->record('@X1@')?->tag());
        self::assertSame("Line one\nline @two and more", $note->value());
        self::assertStringContainsString("1 SNOTE Line one\n2 CONT line @@two\n2 CONC  and more\n1 FAMS @F1@\n0 @X1@ FAM\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function moveNodeKeepsIdsAndWritesTheMovedLinesAnew(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $note = $doc->find('@I1@/NOTE');
        $wife = $doc->find('@F1@/WIFE');
        self::assertNotNull($note);
        self::assertNotNull($wife);

        $doc->moveNode($note->id(), $wife->id());

        $moved = $doc->find('@F1@/WIFE/NOTE');
        self::assertSame($note->id(), $moved?->id());
        self::assertSame("Line one\nline @two and more", $moved->value());
        self::assertStringContainsString("1 FAMS @F1@\n0 @F1@ FAM\n1 WIFE @I1@\n2 NOTE Line one\n3 CONT line @@two\n3 CONC  and more\n0 TRLR\n", Gedcom::serialize($doc));

        $this->expectException(\InvalidArgumentException::class);
        $doc->moveNode($wife->id(), $note->id());
    }

    #[Test]
    public function setXrefMayLeaveDuplicatesAndRecordFindsTheFirst(): void
    {
        $doc = Gedcom::parse("0 HEAD\n0 @a b@ INDI\n0 @a b@ FAM\n0 @I1@ SUBM\n0 TRLR\n");
        [, $indi, $fam, $subm] = $doc->childIds(0);

        $doc->setXref($indi, '@X1@');
        self::assertSame($fam, $doc->record('@a b@')?->id());
        $doc->setXref($subm, '@X1@');
        self::assertSame($indi, $doc->record('@X1@')?->id());
        self::assertNull($doc->record('@I1@'));
    }

    #[Test]
    public function mayContainIsFalseOnlyWhenTheLineCertainlyLacksTheCharacter(): void
    {
        foreach (['UTF-8' => self::FILE, 'UTF-16LE' => "\xFF\xFE" . mb_convert_encoding(self::FILE, 'UTF-16LE', 'UTF-8')] as $encoding => $bytes) {
            $doc = Gedcom::parse($bytes);
            $at = [];
            foreach ($doc->walk() as $id) {
                if ($doc->mayContain($id, '@')) {
                    $at[] = $doc->lineOf($id);
                }
            }
            self::assertSame([9, 11, 13], $at, $encoding);
        }
        $doc = Gedcom::parse(self::FILE);
        $doc->find('@I1@/NAME[0]')?->setValue('a@b');
        self::assertTrue($doc->mayContain($doc->find('@I1@/NAME[0]')?->id() ?? 0, '@'));
    }

    #[Test]
    public function addRecordGoesBeforeTrailer(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $doc->addRecord('SNOTE', '@N1@', 'Shared');

        self::assertStringEndsWith("0 @N1@ SNOTE Shared\n0 TRLR\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function copyIsIndependent(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $copy = $doc->copy();
        $copy->find('@I1@/NAME[0]')?->setValue('Changed');

        self::assertSame('Anna /Koval/', $doc->find('@I1@/NAME[0]')?->value());
    }

    #[Test]
    public function newDocumentHasMandatoryHeader(): void
    {
        $doc = Document::create(Version::V7_0);
        $doc->addRecord('INDI', '@I1@')->addChild('NAME', 'Анна /Коваль/');

        self::assertSame("\xEF\xBB\xBF0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NAME Анна /Коваль/\n0 TRLR\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function lenientModeKeepsBrokenLinesAndReportsThem(): void
    {
        $bytes = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\nwrapped text without level\n0 @I1@ INDI\n3 NAME Jump\n0 TRLR\n";
        $doc = Gedcom::parse($bytes);

        self::assertSame(['KV1001', 'KV1002'], array_map(static fn(\Korin\Gedcom\Diagnostic $d): string => $d->code, $doc->diagnostics()));
        self::assertSame($bytes, Gedcom::serialize($doc));
        self::assertSame('Jump', $doc->find('@I1@/NAME')?->value());
    }

    #[Test]
    public function strictModeThrowsOnErrors(): void
    {
        $this->expectException(ParseException::class);

        Gedcom::parse("0 HEAD\n2 VERS 5.5.1\n0 TRLR\n", (new ReadOptions())->withStrict());
    }

    #[Test]
    public function missingVersionIsReported(): void
    {
        $doc = Gedcom::parse("0 HEAD\n0 TRLR\n");

        self::assertSame(Version::V5_5_1, $doc->version());
        self::assertSame('KV1007', $doc->diagnostics()[0]->code ?? null);
    }

    #[Test]
    public function limitsAreEnforced(): void
    {
        $this->expectException(ParseException::class);

        Gedcom::parse("0 HEAD\n1 NOTE " . str_repeat('x', 100) . "\n", (new ReadOptions())->withLimits(maxDepth: 100, maxLineLength: 50));
    }

    #[Test]
    public function valuesOfUnmodifiedLinesComeFromTheSource(): void
    {
        $doc = Gedcom::parse("0 HEAD\r\n1 GEDC\r\n2 VERS 5.5.1\r\n0 @N1@ NOTE Record text\r\n1 _EMPTY \r\n1 _NONE\r\n0 TRLR");

        self::assertSame('Record text', $doc->record('@N1@')?->value());
        self::assertSame('', $doc->find('@N1@/_EMPTY')?->value());
        $none = $doc->find('@N1@/_NONE');
        self::assertNotNull($none);
        self::assertNull($none->value());
        self::assertSame(1, $none->level());
        self::assertSame(6, $none->line());
        self::assertSame(7, $doc->find('TRLR')?->line());
    }

    #[Test]
    public function renameXrefKeepsTheRecordValue(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n0 @N1@ NOTE Shared text\n0 @I1@ INDI\n1 NOTE @N1@\n0 TRLR\n");
        $doc->renameXref('@N1@', '@N2@');

        self::assertSame('Shared text', $doc->record('@N2@')?->value());
        self::assertSame('@N2@', $doc->find('@I1@/NOTE')?->pointer());
        self::assertStringContainsString("0 @N2@ NOTE Shared text\n0 @I1@ INDI\n1 NOTE @N2@\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function siblingLinksSurviveInsertsAndRemovals(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $indi = $doc->record('@I1@');
        $indi?->addChild('SEX', 'F', position: 0);
        $indi?->first('FAMS')?->remove();
        $indi?->addChild('_LAST', 'x');
        $indi?->first('SEX')?->remove();

        self::assertSame(['NAME', 'NAME', 'NOTE', '_LAST'], array_map(static fn(\Korin\Gedcom\Syntax\Node $n): string => $n->tag(), $indi?->children() ?? []));
        self::assertStringContainsString("2 CONC  and more\n1 _LAST x\n0 @F1@ FAM\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function valueFarIntoALongLineIsKept(): void
    {
        $tag = '_' . str_repeat('T', 2_100_000);
        $bytes = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 {$tag} far value\n0 TRLR\n";
        $doc = Gedcom::parse($bytes, (new ReadOptions())->withLimits(maxDepth: 100, maxLineLength: 3_000_000));

        self::assertSame('far value', $doc->find("HEAD/{$tag}")?->value());
        self::assertSame($bytes, Gedcom::serialize($doc));
    }
    /** @return iterable<string, array{\Closure(Document): mixed}> */
    public static function changes(): iterable
    {
        yield 'addIndividual' => [static fn(Document $d): Individual => $d->addIndividual()];
        yield 'addFamily' => [static fn(Document $d): Family => $d->addFamily()];
        yield 'addRecord' => [static fn(Document $d): Node => $d->addRecord('_X')];
        yield 'removeRecord' => [static fn(Document $d) => $d->removeRecord('@I1@')];
        yield 'renameXref' => [static fn(Document $d) => $d->renameXref('@I1@', '@I9@')];
        yield 'createNode' => [static fn(Document $d): int => $d->createNode(0, null, '_X', null)];
        yield 'removeNode' => [static fn(Document $d) => $d->removeNode(self::id($d, '@I1@/NOTE'))];
        yield 'setPayload' => [static fn(Document $d) => $d->setPayload(self::id($d, '@I1@/NOTE'), 'x')];
        yield 'moveNode' => [static fn(Document $d) => $d->moveNode(self::id($d, '@I1@/NOTE'), self::id($d, '@F1@'))];
        yield 'setTag' => [static fn(Document $d) => $d->setTag(self::id($d, '@I1@/NOTE'), '_NOTE')];
        yield 'setXref' => [static fn(Document $d) => $d->setXref(self::id($d, '@I1@'), '@I9@')];
    }

    #[Test]
    #[DataProvider('changes')]
    public function aFrozenDocumentRejectsEveryChange(\Closure $change): void
    {
        $doc = Gedcom::parse(self::FILE);
        $doc->freeze();

        $this->expectException(ReadOnlyException::class);
        $change($doc);
    }

    /** @return iterable<string, array{\Closure(Document, int): mixed}> */
    public static function nodeChanges(): iterable
    {
        yield 'removeNode' => [static fn(Document $d, int $id) => $d->removeNode($id)];
        yield 'setPayload' => [static fn(Document $d, int $id) => $d->setPayload($id, 'x')];
        yield 'moveNode' => [static fn(Document $d, int $id) => $d->moveNode($id, self::id($d, '@F1@'))];
        yield 'moveNode under it' => [static fn(Document $d, int $id) => $d->moveNode(self::id($d, '@I1@/NAME[0]'), $id)];
        yield 'setTag' => [static fn(Document $d, int $id) => $d->setTag($id, '_NOTE')];
        yield 'setXref' => [static fn(Document $d, int $id) => $d->setXref($id, '@N1@')];
    }

    #[Test]
    #[DataProvider('nodeChanges')]
    public function aRemovedNodeCannotBeChanged(\Closure $change): void
    {
        $doc = Gedcom::parse(self::FILE);
        $id = self::id($doc, '@I1@/NOTE');
        $doc->removeNode($id);

        $this->expectException(DetachedNodeException::class);
        $this->expectExceptionMessage("Node {$id} was removed from the document");
        $change($doc, $id);
    }

    #[Test]
    public function newRecordsTakeTheGivenXrefOrAFreeOne(): void
    {
        $doc = Gedcom::parse(self::FILE);

        self::assertSame('@X7@', $doc->addIndividual('@X7@')->xref());
        self::assertSame('@X8@', $doc->addFamily('@X8@')->xref());
        self::assertNotSame('@I1@', $doc->addIndividual()->xref());
        self::assertNotSame('@F1@', $doc->addFamily()->xref());

        // Without a trailer a record goes last.
        $doc->removeNode(self::id($doc, 'TRLR'));
        $doc->addRecord('_X');
        self::assertSame('_X', $doc->records()[count($doc->records()) - 1]->tag());

        // An unknown xref removes nothing.
        $doc->removeRecord('@NONE@');
        self::assertNotNull($doc->record('@I1@'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Xref @I1@ is already used');
        $doc->addRecord('INDI', '@I1@');
    }

    #[Test]
    public function renameXrefSkipsRemovedPointersAndFindsAddedOnes(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $wife = self::id($doc, '@F1@/WIFE');
        $doc->removeNode($wife);
        $doc->addRecord('_LINK', '@L1@')->addChild('_PTR', '@F1@');

        $doc->renameXref('@F1@', '@F2@');

        self::assertSame('@F2@', $doc->find('@I1@/FAMS')?->pointer());
        self::assertSame('@F2@', $doc->find('@L1@/_PTR')?->pointer());
        self::assertTrue($doc->isRemoved($wife));
    }

    #[Test]
    public function ambiguousPathNamesTheCount(): void
    {
        $this->expectExceptionMessage('Path @I1@/NAME is ambiguous: NAME occurs 2 times');

        Gedcom::parse(self::FILE)->find('@I1@/NAME');
    }

    #[Test]
    public function newDocumentsHaveABomFor70And555Only(): void
    {
        self::assertTrue(Document::create(Version::V7_0)->hasBom());
        self::assertTrue(Document::create(Version::V5_5_5)->hasBom());
        self::assertFalse(Document::create(Version::V5_5_1)->hasBom());
        self::assertSame("\xEF\xBB\xBF0 HEAD\n1 GEDC\n2 VERS 7.0\n0 TRLR\n", Gedcom::serialize(Document::create(Version::V7_0)));
        self::assertSame("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 TRLR\n", Gedcom::serialize(Document::create(Version::V5_5_1)));
    }

    private static function id(Document $doc, string $path): int
    {
        $node = $doc->find($path);
        self::assertNotNull($node);

        return $node->id();
    }
}

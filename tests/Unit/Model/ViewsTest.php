<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Exception\ReadOnlyException;
use Korin\Gedcom\Exception\RequiresDocumentException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Model\Age;
use Korin\Gedcom\Model\Family;
use Korin\Gedcom\Model\GedcomDate;
use Korin\Gedcom\Model\Individual;
use Korin\Gedcom\Model\PersonalName;
use Korin\Gedcom\Model\Place;
use Korin\Gedcom\Model\Sex;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ViewsTest extends TestCase
{
    private const FILE_551 = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n"
        . "0 @I1@ INDI\n1 NAME Іван /Коваль/\n1 SEX m\n1 BIRT\n2 DATE INT 1890 (Easter)\n2 PLAC Полтава, Україна\n1 FAMS @F1@\n"
        . "0 @I2@ INDI\n1 NAME Анна /Шевчук/\n1 SEX F\n1 FAMS @F1@\n"
        . "0 @I3@ INDI\n1 NAME Петро /Коваль/\n1 BIRT\n2 DATE 31 NOV 1915\n2 AGE 0y\n1 DEAT Y\n1 FAMC @F1@\n1 FAMC @F9@\n"
        . "0 @F1@ FAM\n1 HUSB @I1@\n1 WIFE @I2@\n1 CHIL @I3@\n1 MARR\n2 DATE 1912\n"
        . "0 TRLR\n";

    #[Test]
    public function individualsAndFamiliesAreRead(): void
    {
        $doc = Gedcom::parse(self::FILE_551);
        $ivan = $doc->individual('@I1@');
        $petro = $doc->individual('@I3@');

        self::assertCount(3, $doc->individuals());
        self::assertCount(1, $doc->families());
        self::assertNotNull($ivan);
        self::assertNotNull($petro);
        self::assertSame('Іван Коваль', $ivan->name()?->full());
        self::assertSame(Sex::Male, $ivan->sex(), '5.x values are case-insensitive');
        $birth = $ivan->birth();
        $date = $birth?->date();
        self::assertNotNull($date);
        self::assertSame('Easter', $date->phrase);
        self::assertSame('INT 1890 (Easter)', $date->toGedcom(Version::V5_5_1));
        self::assertSame('Полтава', $birth?->place()?->name());
        $born = $petro->birth();
        self::assertNotNull($born);
        self::assertNull($born->date(), 'A day beyond its month is not a date');
        self::assertSame('31 NOV 1915', $born->node()->first('DATE')?->value(), 'Its text stays');
        self::assertEquals(new Age(0), $born->age());
        self::assertSame(['BIRT', 'DEAT'], array_map(static fn(\Korin\Gedcom\Model\Event $e): string => $e->tag(), $petro->events()));
        self::assertSame(['Іван Коваль', 'Анна Шевчук'], array_map(static fn(Individual $i): string => (string) $i->name()?->full(), $petro->parents()));
        self::assertSame(['@F1@', '@F9@'], $petro->familyXrefs());
        self::assertCount(1, $petro->familiesAsChild(), 'A dangling FAMC has no family');
        self::assertSame(['@I3@'], array_map(static fn(Individual $i): ?string => $i->xref(), $ivan->children()));
        self::assertSame(['@I2@'], array_map(static fn(Individual $i): ?string => $i->xref(), $ivan->spouses()));
        self::assertSame('1912', $doc->family('@F1@')?->marriage()?->date()?->toGedcom());
        self::assertNull($doc->family('@I1@'));
    }

    #[Test]
    public function datesAndAgesOf70HaveTheirPhrase(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 BIRT\n2 DATE 1890\n3 PHRASE Easter\n2 AGE < 8y\n3 PHRASE Child\n0 TRLR\n");
        $birth = $doc->individual('@I1@')?->birth();
        self::assertNotNull($birth);

        self::assertSame('Easter', $birth->date()?->phrase);
        self::assertSame('Child', $birth->age()?->phrase);

        $birth->setDate(GedcomDate::parse('ABT 1891'));
        $birth->setAge(Age::parse('< 8y')->withPhrase('Young'));
        self::assertSame("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 BIRT\n2 DATE ABT 1891\n2 AGE < 8y\n3 PHRASE Young\n0 TRLR\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function viewsEditTheTree(): void
    {
        $doc = Gedcom::parse(self::FILE_551);
        $ivan = $doc->individual('@I1@');
        self::assertNotNull($ivan);

        $ivan->editName(static fn(PersonalName $name): PersonalName => $name->withSurname('Ковальов'));
        $birth = $ivan->birth();
        self::assertNotNull($birth);
        $birth->setDate(GedcomDate::parse('1890')->withPhrase('Easter, maybe'))->setPlace(null);
        $ivan->setSex(Sex::Unknown);

        $out = Gedcom::serialize($doc);
        self::assertStringContainsString("1 NAME Іван /Ковальов/\n1 SEX U\n1 BIRT\n2 DATE INT 1890 (Easter, maybe)\n1 FAMS @F1@\n", $out);
        $this->expectException(InvalidValueException::class);
        $ivan->setSex(Sex::Intersex);
    }

    #[Test]
    public function documentIsBuiltWithViews(): void
    {
        $doc = Document::create(Version::V7_0);
        $head = substr(Gedcom::serialize($doc), 0, -strlen("0 TRLR\n"));

        $anna = $doc->addIndividual()
            ->setName(PersonalName::of('Анна', 'Коваль'))
            ->setSex(Sex::Female);
        $anna->addBirth(GedcomDate::parse('12 MAR 1921'), Place::of('Полтава, Україна'));
        $ivan = $doc->addIndividual()->setName(PersonalName::of('Іван', 'Коваль'));
        $ivan->addDeath();
        $family = $doc->addFamily()->setWife($anna)->addChild($ivan)->addChild($ivan);
        $family->addMarriage(GedcomDate::parse('1940'));

        self::assertSame($head
            . "0 @I1@ INDI\n1 NAME Анна /Коваль/\n1 SEX F\n1 BIRT\n2 DATE 12 MAR 1921\n2 PLAC Полтава, Україна\n1 FAMS @F1@\n"
            . "0 @I2@ INDI\n1 NAME Іван /Коваль/\n1 DEAT Y\n1 FAMC @F1@\n"
            . "0 @F1@ FAM\n1 WIFE @I1@\n1 CHIL @I2@\n1 MARR\n2 DATE 1940\n"
            . "0 TRLR\n", Gedcom::serialize($doc));
        self::assertSame([], Gedcom::validate($doc)->errors());

        $family->removeChild($ivan);
        self::assertSame([], $ivan->familyXrefs());
        self::assertSame('@I3@', $doc->addIndividual()->xref());
    }

    #[Test]
    public function indexedDocumentHasTheFullModelReadOnly(): void
    {
        $doc = Gedcom::open($this->handle(self::FILE_551));
        $petro = $doc->individual('@I3@');

        self::assertSame(['@I1@', '@I2@'], array_map(static fn(Individual $i): ?string => $i->xref(), $petro?->parents() ?? []));
        self::assertSame(['@I1@', '@I2@', '@I3@'], array_map(static fn(Individual $i): ?string => $i->xref(), iterator_to_array($doc->individuals(), false)));
        self::assertSame(['@F1@'], array_map(static fn(Family $f): ?string => $f->xref(), iterator_to_array($doc->families(), false)));
        $this->expectException(ReadOnlyException::class);
        $petro?->setSex(Sex::Male);
    }

    #[Test]
    public function streamedRecordHasAViewLimitedToIt(): void
    {
        $views = [];
        foreach (Gedcom::stream($this->handle(self::FILE_551)) as $record) {
            $views[] = $record->view();
        }
        [$head, , , $petro, $family] = $views;

        self::assertNull($head);
        self::assertInstanceOf(Individual::class, $petro);
        self::assertInstanceOf(Family::class, $family);
        self::assertSame(['@F1@', '@F9@'], $petro->familyXrefs());
        self::assertSame(['@I1@', '@I2@', '@I3@'], $family->memberXrefs());
        $this->expectException(RequiresDocumentException::class);
        $petro->parents();
    }

    #[Test]
    public function replacedPartnerLosesItsFams(): void
    {
        $doc = Gedcom::parse(self::FILE_551);
        $family = $doc->family('@F1@');
        $ivan = $doc->individual('@I1@');
        $petro = $doc->individual('@I3@');
        self::assertNotNull($family);
        self::assertNotNull($ivan);
        self::assertNotNull($petro);

        $family->setHusband($petro);

        self::assertSame([], $ivan->familiesAsSpouse());
        self::assertSame(['@F1@'], array_map(static fn(Family $f): ?string => $f->xref(), $petro->familiesAsSpouse()));
        self::assertSame([], array_values(array_filter(Gedcom::validate($doc)->diagnostics(), static fn(\Korin\Gedcom\Diagnostic $d): bool => $d->code === 'KV4004' && str_contains($d->message, '@I1@'))));
    }

    #[Test]
    public function eventIsNotAddedWhenItsDateCannotBeWritten(): void
    {
        $doc = Gedcom::parse(self::FILE_551);
        $anna = $doc->individual('@I2@');
        self::assertNotNull($anna);

        try {
            $anna->addBirth(GedcomDate::parse('BEF 1900')->withPhrase('unsure'));
            self::fail('5.5.1 has no phrase of a range');
        } catch (InvalidValueException) {
            self::assertSame([], $anna->events());
        }
        $this->expectException(InvalidValueException::class);
        $anna->addDeath()->setAge(Age::parse('<8y', Version::V5_5_1)->withPhrase('young'));
    }

    #[Test]
    public function namePiecesFollowTheName(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NAME Анна /Коваль/\n2 GIVN Анна\n2 SURN Коваль\n2 NICK Ганнуся\n2 SURN Kowal\n0 TRLR\n");
        $anna = $doc->individual('@I1@');
        self::assertNotNull($anna);

        $anna->editName(static fn(PersonalName $name): PersonalName => $name->withSurname('Ковальова'));
        self::assertStringContainsString("1 NAME Анна /Ковальова/\n2 GIVN Анна\n2 SURN Ковальова\n2 NICK Ганнуся\n2 SURN Kowal\n", Gedcom::serialize($doc));
        $anna->setName(PersonalName::of('Анна'));
        self::assertStringContainsString("1 NAME Анна\n2 GIVN Анна\n2 NICK Ганнуся\n2 SURN Kowal\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function repeatedLinksGiveEachRelativeOnce(): void
    {
        $doc = Gedcom::parse(str_replace("1 FAMC @F1@\n", "1 FAMC @F1@\n2 PEDI BIRTH\n1 FAMC @F1@\n2 PEDI ADOPTED\n", self::FILE_551));

        self::assertSame(['@I1@', '@I2@'], array_map(static fn(Individual $i): ?string => $i->xref(), $doc->individual('@I3@')?->parents() ?? []));
    }

    #[Test]
    public function editingAnOpenFileIsReadOnly(): void
    {
        $doc = Gedcom::open($this->handle(self::FILE_551));
        $family = $doc->family('@F1@');
        $ivan = $doc->individual('@I1@');
        self::assertNotNull($family);
        self::assertNotNull($ivan);

        $this->expectException(ReadOnlyException::class);
        $family->addChild($ivan);
    }

    #[Test]
    public function removedDuplicateXrefLeavesTheOtherRecord(): void
    {
        $doc = Gedcom::parse("0 HEAD\n0 @I1@ INDI\n1 NAME A\n0 @I1@ INDI\n1 NAME B\n0 TRLR\n");
        $doc->removeRecord('@I1@');

        self::assertSame('B', $doc->individual('@I1@')?->name()?->full());
        self::assertSame('@I2@', $doc->addIndividual()->xref());
        $doc->freeze();
        $this->expectException(ReadOnlyException::class);
        $doc->addFamily();
    }

    /** @return resource */
    private function handle(string $bytes): mixed
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertNotFalse($handle);
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Exception\ReadOnlyException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Model\Event;
use Korin\Gedcom\Model\Family;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Node;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FamilyTest extends TestCase
{
    private const FILE = "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @H@ INDI\n1 FAMS @F1@\n0 @W@ INDI\n1 FAMS @F1@\n0 @C1@ INDI\n1 FAMC @F1@\n1 FAMC @F2@\n0 @C2@ INDI\n1 FAMC @F1@\n"
        . "0 @F1@ FAM\n1 HUSB @H@\n1 WIFE @W@\n1 CHIL @C1@\n1 CHIL @C2@\n1 CHIL @C1@\n1 MARR\n1 DIV\n1 EVEN\n2 TYPE x\n0 @F2@ FAM\n1 CHIL @C1@\n0 TRLR\n";

    #[Test]
    public function membersAreListedOnceInOrder(): void
    {
        self::assertSame(['@H@', '@W@', '@C1@', '@C2@'], $this->family(Gedcom::parse(self::FILE))->memberXrefs());
    }

    #[Test]
    public function removeChildRemovesOnlyThatChildAndItsLinkToThisFamily(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $c1 = $doc->individual('@C1@');
        self::assertNotNull($c1);

        $this->family($doc)->removeChild($c1);

        self::assertSame(['@H@', '@W@', '@C2@'], $this->family($doc)->memberXrefs());
        self::assertSame(['@F2@'], array_map(static fn(Node $n): ?string => $n->pointer(), $c1->node()->all('FAMC')));
        self::assertSame('@F1@', $doc->find('@C2@/FAMC')?->pointer());
    }

    #[Test]
    public function eventsAreAllOrThoseOfTheTags(): void
    {
        $family = $this->family(Gedcom::parse(self::FILE));

        self::assertSame(['MARR', 'DIV', 'EVEN'], array_map(static fn(Event $e): string => $e->node()->tag(), $family->events()));
        self::assertSame(['DIV'], array_map(static fn(Event $e): string => $e->node()->tag(), $family->events('DIV')));
    }

    #[Test]
    public function aReplacedPartnerLosesItsFamsUnlessItIsStillAPartner(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $c2 = $doc->individual('@C2@');
        $w = $doc->individual('@W@');
        self::assertNotNull($c2);
        self::assertNotNull($w);

        $this->family($doc)->setHusband($c2);
        self::assertNull($doc->find('@H@/FAMS'));
        self::assertSame('@F1@', $this->fams($doc, '@C2@'));

        // The wife becomes the husband too: as she is still WIFE, she keeps FAMS.
        $this->family($doc)->setHusband($w);
        self::assertNull($doc->find('@C2@/FAMS'));
        self::assertSame('@F1@', $this->fams($doc, '@W@'));
        $this->family($doc)->setHusband($c2);
        self::assertSame(['@F1@'], array_map(static fn(Node $n): ?string => $n->pointer(), $w->node()->all('FAMS')));
    }

    #[Test]
    public function membersMustBeOfTheSameDocument(): void
    {
        $other = Gedcom::parse(self::FILE)->individual('@C2@');
        self::assertNotNull($other);

        $this->expectException(InvalidValueException::class);
        $this->family(Gedcom::parse(self::FILE))->addChild($other);
    }

    #[Test]
    public function aFamilyWithoutXrefTakesNoMembers(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $c2 = $doc->individual('@C2@');
        self::assertNotNull($c2);
        $family = new Family($doc->addRecord('FAM'), null);

        $this->expectException(InvalidValueException::class);
        $family->addChild($c2);
    }

    #[Test]
    public function aReadOnlyIndividualIsNotLinked(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $c2 = $doc->individual('@C2@');
        self::assertNotNull($c2);
        $doc->freeze();

        $this->expectException(ReadOnlyException::class);
        $this->family(Gedcom::parse(self::FILE))->removeChild($c2);
    }

    #[Test]
    public function aReadOnlyFamilyIsNotChanged(): void
    {
        $doc = Gedcom::parse(self::FILE);
        $doc->freeze();
        $other = Gedcom::parse(self::FILE)->individual('@C2@');
        self::assertNotNull($other);

        $this->expectException(ReadOnlyException::class);
        $this->family($doc)->addChild($other);
    }

    private function fams(Document $doc, string $xref): ?string
    {
        return $doc->find("{$xref}/FAMS")?->pointer();
    }

    private function family(Document $doc): Family
    {
        $family = $doc->family('@F1@');
        self::assertNotNull($family);

        return $family;
    }
}

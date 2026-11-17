<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Extension;

use Korin\Gedcom\Extension\Program;
use Korin\Gedcom\Extension\SourceSystem;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DialectsTest extends TestCase
{
    /** @return iterable<string, array{string, ?Program}> */
    public static function identifiers(): iterable
    {
        yield 'exact' => ['RootsMagic', Program::RootsMagic];
        yield 'any case' => ['GRAMPS', Program::Gramps];
        yield 'Ancestry trees' => ['Ancestry.com Family Trees', Program::Ancestry];
        yield 'PAF with a version' => ['PAF 2.31', Program::PersonalAncestralFile];
        yield 'Heredis of a year' => ['HEREDIS PC2018', Program::Heredis];
        yield 'Family Tree Maker for Windows' => ['FTW', Program::FamilyTreeMaker];
        yield 'MacFamilyTree' => ['SyniumFamilyTree', Program::MacFamilyTree];
        yield 'unknown' => ['Korin', null];
        yield 'PAF in a longer name' => ['PAFIT', null];
    }

    #[Test]
    #[DataProvider('identifiers')]
    public function programsAreKnownByTheirIdentifier(string $identifier, ?Program $program): void
    {
        self::assertSame($program, Program::fromIdentifier($identifier));
    }

    #[Test]
    public function sourceSystemIsReadFromHead(): void
    {
        $document = Gedcom::parse("0 HEAD\n1 SOUR PAF 5.0\n2 NAME Personal Ancestral File\n1 GEDC\n2 VERS 5.5.1\n0 TRLR\n");
        $system = $document->sourceSystem();

        self::assertNotNull($system);
        self::assertEquals(new SourceSystem('PAF 5.0', null, 'Personal Ancestral File'), $system);
        self::assertSame('5.0', $system->version());
        self::assertSame(Program::PersonalAncestralFile, $system->program());
        self::assertTrue((new SourceSystem('BROSKEEP', '6.1.11'))->isBefore('6.1.12'));
        self::assertFalse((new SourceSystem('BROSKEEP', '6.1.12'))->isBefore('6.1.12'));
        self::assertFalse((new SourceSystem('BROSKEEP'))->isBefore('6.1.12'));
        self::assertNull(Gedcom::parse("0 HEAD\n0 TRLR\n")->sourceSystem());
    }

    #[Test]
    public function paf5IsRead551(): void
    {
        $bytes = "0 HEAD\n1 SOUR PAF\n2 VERS 5.2.18.0\n1 GEDC\n2 VERS 5.5\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 TRLR\n";

        self::assertSame(Version::V5_5_1, Gedcom::parse($bytes)->version());
        self::assertSame(['KV1010'], array_map(static fn(\Korin\Gedcom\Diagnostic $d): string => $d->code, Gedcom::parse($bytes)->diagnostics()));
        self::assertSame(Version::V5_5_1, Gedcom::open($this->handle($bytes))->version());
        self::assertSame(Version::V5_5, Gedcom::parse($bytes, (new ReadOptions())->withDialects(false))->version());
        self::assertSame(Version::V5_5, Gedcom::parse(str_replace('5.2.18.0', '4.01', $bytes))->version(), 'PAF 4 wrote 5.5');
    }

    #[Test]
    public function brothersKeeperIbmpcIsWindows1252(): void
    {
        $bytes = "0 HEAD\r\n1 SOUR BROSKEEP\r\n2 VERS 5.2\r\n1 CHAR IBMPC\r\n0 @I1@ INDI\r\n1 NAME Ren\xE9e\r\n0 TRLR\r\n";

        self::assertSame('Renée', Gedcom::parse($bytes)->record('@I1@')?->first('NAME')?->value());
        self::assertSame(Encoding::Cp437, Gedcom::parse($bytes, (new ReadOptions())->withDialects(false))->encoding());
        self::assertSame(Encoding::Cp437, Gedcom::parse(str_replace('5.2', '6.1.12', $bytes))->encoding(), 'Fixed in 6.1.12');
        self::assertSame(Encoding::Cp437, Gedcom::parse(str_replace('BROSKEEP', 'Legacy', $bytes))->encoding(), 'Only Brother\'s Keeper');
    }

    #[Test]
    public function asciiWithMacRomanVersIsMacRoman(): void
    {
        $bytes = "0 HEAD\n1 SOUR MacFamilyTree\n1 CHAR ASCII\n2 VERS MACINTOSH\n0 @I1@ INDI\n1 NAME Ren\x8Ee\n0 TRLR\n";
        $streamed = '';
        foreach (Gedcom::stream($this->handle($bytes)) as $record) {
            $streamed .= $record->node()->first('NAME')?->value();
        }

        self::assertSame('Renée', Gedcom::parse($bytes)->record('@I1@')?->first('NAME')?->value());
        self::assertSame('Renée', $streamed);
        self::assertSame(Encoding::Ascii, Gedcom::parse(str_replace("Ren\x8Ee", 'Rene', $bytes))->encoding(), 'ASCII content stays ASCII');
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

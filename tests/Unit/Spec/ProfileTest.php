<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Spec;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private const V7 = 'https://gedcom.io/terms/v7/';

    #[Test]
    public function version7ComesFromTheFamilySearchTerms(): void
    {
        $profile = Profile::for(Version::V7_0);

        self::assertSame(Version::V7_0, $profile->version());
        self::assertStringStartsWith('FamilySearch GEDCOM v7.0.', $profile->source());
        self::assertSame(['FAM', 'HEAD', 'INDI', 'OBJE', 'REPO', 'SNOTE', 'SOUR', 'SUBM', 'TRLR'], array_keys($profile->records()));
        self::assertSame('{1:1}', (string) $profile->record('HEAD')?->cardinality);
        self::assertSame('{0:M}', (string) $profile->record('INDI')?->cardinality);
        self::assertNull($profile->record('_CUSTOM'));
        self::assertSame($profile, Profile::for(Version::V7_0));
    }

    #[Test]
    public function sameTagMeansDifferentStructuresInDifferentPlaces(): void
    {
        $profile = Profile::for(Version::V7_0);

        self::assertSame(self::V7 . 'INDI-NAME', $profile->resolve(['INDI', 'NAME'])?->uri);
        self::assertSame(self::V7 . 'NAME', $profile->resolve(['REPO', 'NAME'])?->uri);
        self::assertSame(self::V7 . 'DATE', $profile->resolve(['INDI', 'BIRT', 'DATE'])?->uri);
        self::assertNull($profile->resolve(['INDI', '_UID']));
        self::assertNull($profile->resolve(['INDI', 'NAME', 'NAME']));
        self::assertNull($profile->resolve([]));
    }

    #[Test]
    public function structuresKnowSubstructuresPayloadsAndEnumerations(): void
    {
        $profile = Profile::for(Version::V7_0);
        $indi = $profile->structure(self::V7 . 'record-INDI');
        $sex = $profile->resolve(['INDI', 'SEX']);
        self::assertNotNull($sex);

        self::assertSame('{0:1}', (string) $indi?->substructure('SEX')?->cardinality);
        self::assertSame('{0:M}', (string) $indi?->substructure('NAME')?->cardinality);
        self::assertNull($indi?->substructure('CONT'));
        self::assertSame(self::V7 . 'record-FAM', $profile->resolve(['INDI', 'FAMC'])?->pointerTarget());
        self::assertNull($sex->pointerTarget());
        self::assertSame(self::V7 . 'type-Enum', $sex->payload);
        self::assertSame(['F', 'M', 'U', 'X'], $profile->enumeration((string) $sex->enumerationSet));
        self::assertSame('Y|<NULL>', $profile->resolve(['INDI', 'BIRT'])?->payload);
        self::assertTrue($profile->resolve(['HEAD', 'GEDC', 'VERS'])?->payload !== null);
    }

    #[Test]
    public function calendarsListTheirMonths(): void
    {
        $calendars = Profile::for(Version::V7_0)->calendars();

        self::assertSame(['FRENCH_R', 'GREGORIAN', 'HEBREW', 'JULIAN'], array_keys($calendars));
        self::assertCount(12, $calendars['GREGORIAN']['months']);
        self::assertCount(13, $calendars['HEBREW']['months']);
        self::assertSame(['BCE'], $calendars['JULIAN']['epochs']);
    }

    #[Test]
    public function deprecationsComeFromTheTranscription(): void
    {
        $profile = Profile::for(Version::V7_0);

        self::assertSame('the ADDR value', $profile->deprecation('https://gedcom.io/terms/v7/ADR1'));
        self::assertNull($profile->deprecation('https://gedcom.io/terms/v7/ADDR'));
        self::assertSame('CHILD', $profile->deprecatedValue('https://gedcom.io/terms/v7/enumset-ord-STAT', 'INFANT'));
        self::assertSame('', $profile->deprecatedValue('https://gedcom.io/terms/v7/enumset-ord-STAT', 'EXCLUDED'));
        self::assertNull($profile->deprecatedValue('https://gedcom.io/terms/v7/enumset-ord-STAT', 'CHILD'));
        self::assertSame('TYPE', $profile->deprecatedWithout('https://gedcom.io/terms/v7/EXID'));
        self::assertSame('COMP', $profile->months()['https://gedcom.io/terms/v7/month-COMP']);
        self::assertNull(Profile::for(Version::V5_5_1)->deprecation('https://gedcom.io/terms/v7/ADR1'));
    }

    #[Test]
    public function everyStructureIsReachableFromTheTopLevel(): void
    {
        $profile = Profile::for(Version::V7_0);
        $seen = [];
        $queue = array_map(static fn(\Korin\Gedcom\Spec\Substructure $s): string => $s->uri, $profile->records());
        while ($queue !== []) {
            $uri = array_pop($queue);
            if (isset($seen[$uri])) {
                continue;
            }
            $structure = $profile->structure($uri);
            self::assertNotNull($structure, $uri);
            $seen[$uri] = true;
            foreach ($structure->substructures as $child) {
                $queue[] = $child->uri;
            }
        }

        // Every structure except CONT is reachable from the top level.
        self::assertCount(179, $seen);
    }

    #[Test]
    public function version551ComesFromTheGrammarOfTheSpecification(): void
    {
        $profile = Profile::for(Version::V5_5_1);
        $base = 'https://gedcom.io/specifications/ged551.pdf#';

        self::assertSame(Version::V5_5_1, $profile->version());
        self::assertStringStartsWith('The GEDCOM Standard, Release 5.5.1', $profile->source());
        self::assertSame(['FAM', 'HEAD', 'INDI', 'NOTE', 'OBJE', 'REPO', 'SOUR', 'SUBM', 'SUBN', 'TRLR'], array_keys($profile->records()));
        self::assertSame('{0:1}', (string) $profile->record('SUBN')?->cardinality);
        self::assertSame('{1:1}', (string) $profile->resolve(['HEAD'])?->substructure('SUBM')?->cardinality);

        // An included structure keeps its identity: the same DATE in a birth and in a marriage.
        self::assertSame($base . 'EVENT_DETAIL/DATE', $profile->resolve(['INDI', 'BIRT', 'DATE'])?->uri);
        self::assertSame($base . 'EVENT_DETAIL/DATE', $profile->resolve(['FAM', 'MARR', 'DATE'])?->uri);
        self::assertSame($base . 'INDIVIDUAL_EVENT_STRUCTURE/BIRT/FAMC', $profile->resolve(['INDI', 'BIRT', 'FAMC'])?->uri);
        self::assertSame($base . 'CHILD_TO_FAMILY_LINK/FAMC', $profile->resolve(['INDI', 'FAMC'])?->uri);
        self::assertNull($profile->resolve(['INDI', 'DEAT', 'FAMC']));
        // A group of lines (ADDRESS_STRUCTURE) brings all its tags.
        self::assertSame('{0:3}', (string) $profile->resolve(['REPO'])?->substructure('PHON')?->cardinality);

        // Alternatives with one tag are merged; a substructure is required only in all of them.
        $note = $profile->resolve(['INDI', 'NOTE']);
        self::assertNotNull($note);
        self::assertSame('@<' . $base . 'NOTE_RECORD/NOTE>@|<SUBMITTER_TEXT>|<NULL>', $note->payload);
        self::assertSame($base . 'NOTE_RECORD/NOTE', $note->pointerTarget());
        $link = $profile->resolve(['INDI', 'OBJE']);
        self::assertNotNull($link);
        self::assertSame('{0:M}', (string) $link->substructure('FILE')?->cardinality);
        self::assertSame('@<' . $base . 'MULTIMEDIA_RECORD/OBJE>@|<NULL>', $link->payload);
        // Each form keeps its own substructures: OBJE @O1@ has none, an inline OBJE requires FILE.
        self::assertSame([], $link->allowed('@O1@'));
        self::assertSame(['FILE', 'FORM', 'TITL'], array_keys($link->allowed(null)));
        self::assertSame('{1:M}', (string) $link->allowed(null)['FILE']->cardinality);
        // A text, which neither form has, gets the substructures of both.
        self::assertSame($link->substructures, $link->allowed('text'));
        $citation = $profile->resolve(['INDI', 'SOUR']);
        self::assertNotNull($citation);
        self::assertArrayNotHasKey('TEXT', $citation->allowed('@S1@'));
        self::assertArrayNotHasKey('PAGE', $citation->allowed('Parish register'));
        self::assertSame($note->substructures, $note->allowed('@N1@'));
        // A malformed pointer is the form with a pointer; a structure without forms has none.
        self::assertSame('pointer', $citation->form('@S1'));
        self::assertSame('inline', $citation->form('Parish register'));
        self::assertNull($link->form('text'));
        self::assertNull($note->form('@N1@'));
        self::assertSame([['NOTE', 'SNOTE']], Profile::for(Version::V7_0)->choices('https://gedcom.io/terms/v7/HEAD'));
        self::assertSame([], $profile->choices($base . 'HEADER/HEAD'));
        self::assertSame('Y|<NULL>', $profile->resolve(['INDI', 'BIRT'])?->payload);
        self::assertNull($profile->resolve(['INDI', 'BIRT'])->pointerTarget());
        self::assertNull($profile->resolve(['INDI', 'NAME', 'CONT']));
    }

    #[Test]
    public function version55HasItsOwnGrammar(): void
    {
        $profile = Profile::for(Version::V5_5);
        $base = 'https://gedcom.io/specifications/ged55.pdf#';

        self::assertSame(Version::V5_5, $profile->version());
        self::assertStringStartsWith('The GEDCOM Standard, Release 5.5 ', $profile->source());
        self::assertSame(['FAM', 'HEAD', 'INDI', 'NOTE', 'OBJE', 'REPO', 'SOUR', 'SUBM', 'SUBN', 'TRLR'], array_keys($profile->records()));
        // Lines written under <<FAMILY_EVENT_STRUCTURE>> belong to each family event.
        self::assertSame($base . 'FAM_RECORD/FAM/FAMILY_EVENT_STRUCTURE/HUSB', $profile->resolve(['FAM', 'MARR', 'HUSB'])?->uri);
        self::assertSame('{1:1}', (string) $profile->resolve(['FAM', 'DIV', 'WIFE'])?->substructure('AGE')?->cardinality);
        self::assertSame($base . 'FAM_RECORD/FAM/HUSB', $profile->resolve(['FAM', 'HUSB'])?->uri);
        // Structures of 5.5 that 5.5.1 changed or dropped.
        self::assertSame('{1:1}', (string) $profile->resolve(['OBJE'])?->substructure('BLOB')?->cardinality);
        self::assertSame('{1:1}', (string) $profile->resolve(['INDI', 'ASSO'])?->substructure('TYPE')?->cardinality);
        self::assertSame('<ADDRESS_LINE>', $profile->resolve(['REPO', 'ADDR'])?->payload);
        self::assertNull($profile->resolve(['INDI', 'EVEN'])?->payload);
        // NOTE_STRUCTURE and SOURCE_CITATION include each other.
        self::assertSame($base . 'SOURCE_CITATION/SOUR', $profile->resolve(['INDI', 'NOTE', 'SOUR'])?->uri);
        self::assertSame($base . 'NOTE_STRUCTURE/NOTE', $profile->resolve(['INDI', 'NOTE', 'SOUR', 'NOTE'])?->uri);
        self::assertSame(['M', 'F'], $profile->enumeration('<SEX_VALUE>'));
    }

    #[Test]
    public function version555HasItsOwnGrammarAndSizes(): void
    {
        $profile = Profile::for(Version::V5_5_5);
        $base = 'https://www.gedcom.org/specs/GEDCOM555.zip#';

        self::assertSame(Version::V5_5_5, $profile->version());
        // No SUBN; exactly one SUBM.
        self::assertSame(['FAM', 'HEAD', 'INDI', 'NOTE', 'OBJE', 'REPO', 'SOUR', 'SUBM', 'TRLR'], array_keys($profile->records()));
        self::assertSame('{1:1}', (string) $profile->record('SUBM')?->cardinality);
        self::assertSame('LINEAGE-LINKED', $profile->resolve(['HEAD', 'GEDC', 'FORM'])?->payload);
        self::assertSame('{1:1}', (string) $profile->resolve(['HEAD', 'GEDC', 'FORM'])->substructure('VERS')?->cardinality);
        // Pointers written <XREF:FAM> without "@".
        self::assertSame($base . 'FAM_GROUP_RECORD/FAM', $profile->resolve(['INDI', 'FAMC'])?->pointerTarget());
        // The TYPE written in an attribute takes precedence over the TYPE of its event detail.
        self::assertSame($base . 'INDIVIDUAL_ATTRIBUTE_STRUCTURE/IDNO/TYPE', $profile->resolve(['INDI', 'IDNO', 'TYPE'])?->uri);
        self::assertSame('{1:1}', (string) $profile->resolve(['INDI', 'IDNO'])?->substructure('TYPE')?->cardinality);
        self::assertSame($base . 'EVENT_DETAIL/TYPE', $profile->resolve(['INDI', 'BIRT', 'TYPE'])?->uri);
        self::assertNull($profile->resolve(['INDI', 'BIRT'])?->payload);
        self::assertSame([1, 60], $profile->size('<ADDRESS_CITY>'));
        self::assertNull($profile->size('<DATE_VALUE>'));
        self::assertNull(Profile::for(Version::V5_5_1)->size('<ADDRESS_CITY>'));
    }

    #[Test]
    #[DataProvider('grammarVersions')]
    public function everyGrammarStructureIsReachable(Version $version, int $count): void
    {
        $profile = Profile::for($version);
        $seen = [];
        $queue = array_map(static fn(\Korin\Gedcom\Spec\Substructure $s): string => $s->uri, $profile->records());
        while ($queue !== []) {
            $uri = array_pop($queue);
            if (!isset($seen[$uri])) {
                $seen[$uri] = true;
                foreach ($profile->structure($uri)->substructures ?? [] as $child) {
                    $queue[] = $child->uri;
                }
            }
        }

        self::assertCount($count, $seen);
    }

    /** @return iterable<string, array{Version, int}> */
    public static function grammarVersions(): iterable
    {
        yield '5.5' => [Version::V5_5, 219];
        yield '5.5.1' => [Version::V5_5_1, 248];
        yield '5.5.5' => [Version::V5_5_5, 195];
    }

    #[Test]
    public function everyVersionHasAProfile(): void
    {
        foreach (Version::cases() as $version) {
            // 4.0 is read with the 5.5 profile.
            self::assertSame($version->readAs(), Profile::for($version)->version());
        }
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Spec;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Version;
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
        self::assertSame('Y|<NULL>', $profile->resolve(['INDI', 'BIRT'])?->payload);
        self::assertNull($profile->resolve(['INDI', 'BIRT'])->pointerTarget());
        self::assertNull($profile->resolve(['INDI', 'NAME', 'CONT']));
    }

    #[Test]
    public function every551StructureIsReachable(): void
    {
        $profile = Profile::for(Version::V5_5_1);
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

        self::assertCount(248, $seen);
    }

    #[Test]
    public function missingProfileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Profile::for(Version::V5_5);
    }
}

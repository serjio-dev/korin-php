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
        self::assertFalse(Profile::has(Version::V5_5_1));
    }

    #[Test]
    public function missingProfileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Profile::for(Version::V5_5_1);
    }
}

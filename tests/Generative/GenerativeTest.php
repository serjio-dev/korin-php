<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Generative;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Tests\Support\Conformance\Cases;
use Korin\Gedcom\Tests\Support\ConversionInvariants;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SPEC section 10, test type 6: random valid documents of every writable version, drawn from the
 * grammar of its profile (Cases::random()), go through the conversion matrix and, from 7.0, up and
 * down again with the invariants of the corpus. KORIN_SEEDS sets how many documents of each version
 * (10 by default), KORIN_SEED the first seed: a failure names its seed, and the seed gives the file again.
 */
final class GenerativeTest extends TestCase
{
    use ConversionInvariants;

    private const V5 = ['5.5', '5.5.1', '5.5.5'];

    /**
     * Errors of the output that are bugs known from these tests, to be fixed (.claude/tasks/1.0.md,
     * 0.7.3): input versions, target versions, code and a pattern of the message. An entry goes when
     * its bug is fixed.
     */
    private const KNOWN = [
        // 7.0 HEAD.SNOTE becomes a HEAD.NOTE pointer, which 5.x does not allow.
        [['7.0'], self::V5, 'KV3004', '/^NOTE must have a value, not a pointer$/'],
        // FONE and ROMN with a NOTE or a SOUR become a TRAN, which has neither.
        [self::V5, ['7.0'], 'KV2001', '/^(SNOTE|SOUR) is not allowed in TRAN$/'],
        // A structure with neither a value nor substructures that is not an event (BAPL, SOUR.DATA) stays empty.
        [self::V5, ['7.0'], 'KV3007', '/ has neither a value nor substructures$/'],
        // An inline OBJE with more than one FILE, or inside an inline SOUR, becomes a media record FORM is missing from or misplaced in.
        [self::V5, ['5.5.5', '7.0'], 'KV2003', '/^FILE requires FORM$/'],
        [['5.5'], ['7.0'], 'KV2001', '/^FORM is not allowed in OBJE$/'],
        [['5.5'], ['7.0'], 'KV3005', '/^FILE must be a file path/'],
        // An extension that the next step of the chain makes a standard structure again keeps its value:
        // 7.0 ROLE under NOTE.SOUR, which 5.5.1 does not have, reaches 5.5 unconverted.
        [['7.0'], ['5.5'], 'KV3006', '/^ROLE does not permit/'],
    ];

    /**
     * Losses of known bugs whose structures up-down-up leaves out: a DATE that 5.5.1 does not have
     * where it stands is _DATE with a 5.5.1 value, and comes back as a DATE with that value.
     */
    private const KNOWN_LOSSES = [['KL2001', '/has no DATE here$/']];

    /** @return iterable<string, array{Version, int}> */
    public static function documents(): iterable
    {
        $first = getenv('KORIN_SEED');
        $count = getenv('KORIN_SEEDS');
        $first = $first === false || $first === '' ? 1 : (int) $first;
        $count = $count === false || $count === '' ? 10 : (int) $count;
        foreach (self::versions() as $version) {
            for ($seed = $first; $seed < $first + $count; ++$seed) {
                yield "{$version->value} seed {$seed}" => [$version, $seed];
            }
        }
    }

    /** @return iterable<string, array{Version, int, Version}> */
    public static function cells(): iterable
    {
        foreach (self::documents() as $name => [$version, $seed]) {
            foreach (self::versions() as $to) {
                yield "{$name} to {$to->value}" => [$version, $seed, $to];
            }
        }
    }

    /** @return iterable<string, array{int, Version}> */
    public static function chains(): iterable
    {
        foreach (self::documents() as $name => [$version, $seed]) {
            if ($version === Version::V7_0) {
                foreach ([Version::V5_5_1, Version::V5_5, Version::V5_5_5] as $via) {
                    yield "{$name} via {$via->value}" => [$seed, $via];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('documents')]
    public function theDocumentsAreValid(Version $version, int $seed): void
    {
        $document = Gedcom::parse(Cases::random(Profile::for($version), $seed));

        self::assertSame($version, $document->version());
        // Deprecated structures (KV2005) are valid: they are drawn too.
        $diagnostics = array_filter(Gedcom::validate($document)->diagnostics(), static fn(Diagnostic $d): bool => $d->code !== 'KV2005');
        self::assertSame([], array_values(array_map(strval(...), $diagnostics)));
        self::assertSame(Cases::random(Profile::for($version), $seed), Cases::random(Profile::for($version), $seed), 'The seed gives the same file');
    }

    #[Test]
    #[DataProvider('cells')]
    public function conversionsKeepTheInvariantsOfTheMatrix(Version $version, int $seed, Version $to): void
    {
        $known = [];
        foreach (self::KNOWN as [$from, $targets, $code, $pattern]) {
            if (in_array($version->value, $from, true) && in_array($to->value, $targets, true)) {
                $known[] = [$code, $pattern];
            }
        }
        $this->assertConvertsCleanly(Cases::random(Profile::for($version), $seed), $to, $known);
    }

    #[Test]
    #[DataProvider('chains')]
    public function upDownUpKeepsTheDocument(int $seed, Version $via): void
    {
        $this->assertUpDownUp(Cases::random(Profile::for(Version::V7_0), $seed), $via, self::KNOWN_LOSSES);
    }

    /** @return list<Version> */
    private static function versions(): array
    {
        return array_values(array_filter(Version::cases(), static fn(Version $v): bool => $v->isWritable()));
    }
}

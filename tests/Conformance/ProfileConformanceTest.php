<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Conformance;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Tests\Support\Conformance\Cases;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Specification conformance (SPEC.md section 10, kind 2): cases generated from every profile
 * by Cases. Diagnostics of structure, values and references are compared; reader diagnostics
 * and KV4004 (family links that are not mutual, which minimal files do not make) are not.
 */
final class ProfileConformanceTest extends TestCase
{
    /** @return iterable<string, array{Version, string, list<string>}> */
    public static function cases(): iterable
    {
        foreach (Version::cases() as $version) {
            // 4.0 has no profile of its own: it is read with that of 5.5.
            if ($version->readAs() === $version && Profile::has($version)) {
                yield from Cases::of(Profile::for($version));
            }
        }
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('cases')]
    public function profileIsEnforced(Version $version, string $file, array $expected): void
    {
        $report = Gedcom::validate(Gedcom::parse($file, (new ReadOptions())->withVersion($version)));
        $actual = [];
        foreach ($report->diagnostics() as $diagnostic) {
            // Family links (KV4004) and deprecations (KV2005) are not what the cases are about.
            if (preg_match('/^KV[234]/', $diagnostic->code) === 1 && !in_array($diagnostic->code, ['KV4004', 'KV2005'], true)) {
                $actual[] = $diagnostic->code . ($diagnostic->line === null ? ' file' : " line {$diagnostic->line}");
            }
        }
        sort($actual);

        self::assertSame($expected, $actual, $file . "\n" . implode("\n", array_map(strval(...), $report->diagnostics())));
    }
}

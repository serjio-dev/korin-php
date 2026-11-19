<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Corpus;

use Korin\Gedcom\Tests\Support\ConversionInvariants;
use Korin\Gedcom\Tests\Support\Corpus;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** SPEC section 10, test type 5 on every 7.0 input of the corpus, via 5.5.1, 5.5 and 5.5.5. */
final class UpDownUpTest extends TestCase
{
    use ConversionInvariants;

    /** @return iterable<string, array{string, Version}> */
    public static function inputs(): iterable
    {
        foreach (Corpus::cases() as $dir => $case) {
            /** @var array{input: array{file: string, version: string}} $case */
            if ($case['input']['version'] === '7.0' && str_ends_with($case['input']['file'], '.ged')) {
                foreach ([Version::V5_5_1, Version::V5_5, Version::V5_5_5] as $via) {
                    yield basename($dir) . ' via ' . $via->value => [$dir . '/' . $case['input']['file'], $via];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('inputs')]
    public function upDownUpKeepsTheDocument(string $file, Version $via): void
    {
        $this->assertUpDownUp((string) file_get_contents($file), $via);
    }
}

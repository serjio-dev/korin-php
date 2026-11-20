<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Corpus;

use Korin\Gedcom\Tests\Support\ConversionInvariants;
use Korin\Gedcom\Tests\Support\Corpus;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SPEC section 10, test type 4: every corpus input converted to every version (the conversion
 * matrix). The output validates without errors, other than those the input has (at the same structure) and
 * the documented limits of the target (docs/conversion.md); the conversion is deterministic,
 * and converting the output to its own version changes nothing. The loss reports of the cells
 * a case names are checked against its golden files by CorpusTest.
 */
final class MatrixTest extends TestCase
{
    use ConversionInvariants;

    /** @return iterable<string, array{string, Version}> */
    public static function cells(): iterable
    {
        foreach (Corpus::cases() as $dir => $case) {
            /** @var array{input: array{file: string}} $case */
            if (str_ends_with($case['input']['file'], '.ged')) {
                foreach (array_filter(Version::cases(), static fn(Version $to): bool => $to->isWritable()) as $to) {
                    yield basename($dir) . ' to ' . $to->value => [$dir . '/' . $case['input']['file'], $to];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('cells')]
    public function theOutputIsValidDeterministicAndStable(string $file, Version $to): void
    {
        $this->assertConvertsCleanly((string) file_get_contents($file), $to);
    }
}

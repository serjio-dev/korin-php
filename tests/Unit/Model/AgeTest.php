<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Model\Age;
use Korin\Gedcom\Model\AgeBound;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgeTest extends TestCase
{
    /** @return iterable<string, array{string, Version, Age, string, string}> value, version, age, in 7.0, in 5.5.1 ("!" when it cannot) */
    public static function ages(): iterable
    {
        yield 'years and months' => ['35y 3m', Version::V7_0, new Age(35, 3), '35y 3m', '35y 3m'];
        yield 'less, 7.0' => ['< 1y', Version::V7_0, new Age(1, bound: AgeBound::Less), '< 1y', '<1y'];
        yield 'greater, 5.5.1' => ['>79y', Version::V5_5_1, new Age(79, bound: AgeBound::Greater), '> 79y', '>79y'];
        yield 'weeks' => ['2w 3d', Version::V7_0, new Age(weeks: 2, days: 3), '2w 3d', '!'];
        yield 'uppercase units' => ['1Y 2M', Version::V5_5_1, new Age(1, 2), '1y 2m', '1y 2m'];
        yield 'word' => ['child', Version::V5_5_1, new Age(word: Age::CHILD), '!', 'CHILD'];
        yield 'empty, 7.0' => ['', Version::V7_0, new Age(), '', '!'];
        yield '5.5.5' => ['< 8y', Version::V5_5_5, new Age(8, bound: AgeBound::Less), '< 8y', '<8y'];
    }

    #[Test]
    #[DataProvider('ages')]
    public function agesAreParsedAndWritten(string $value, Version $version, Age $expected, string $in70, string $in551): void
    {
        $age = Age::parse($value, $version);

        self::assertEquals($expected, $age);
        self::assertEquals($age, Age::parse($age->toGedcom($version), $version));
        self::assertSame($in70, $this->written($age, Version::V7_0));
        self::assertSame($in551, $this->written($age, Version::V5_5_1));
    }

    #[Test]
    public function phraseIsKept(): void
    {
        $age = Age::parse('< 8y')->withPhrase('Child');

        self::assertSame('Child', $age->phrase);
        self::assertSame('< 8y', (string) $age);
        self::assertTrue(Age::parse('')->withPhrase('young')->isEmpty());
    }

    /** @return iterable<string, array{string, Version}> */
    public static function invalid(): iterable
    {
        yield 'no space after the bound in 7.0' => ['<1y', Version::V7_0];
        yield 'word in 7.0' => ['CHILD', Version::V7_0];
        yield 'weeks in 5.5.1' => ['2w', Version::V5_5_1];
        yield 'text' => ['about 30', Version::V5_5_1];
        yield 'units out of order' => ['3m 35y', Version::V7_0];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function invalidAgesAreNotParsed(string $value, Version $version): void
    {
        self::assertNull(Age::tryParse($value, $version));
        $this->expectException(InvalidValueException::class);
        Age::parse($value, $version);
    }

    #[Test]
    public function inconsistentAgesAreRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new Age(5, word: Age::CHILD);
    }

    private function written(Age $age, Version $version): string
    {
        try {
            return $age->toGedcom($version);
        } catch (InvalidValueException) {
            return '!';
        }
    }
}

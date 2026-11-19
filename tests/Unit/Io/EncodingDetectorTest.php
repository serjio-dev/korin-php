<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Io;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Io\EncodingDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncodingDetectorTest extends TestCase
{
    private const HEAD = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR %s\n0 @I1@ INDI\n1 NAME %s\n0 TRLR\n";

    /** @return iterable<string, array{string, Encoding, list<string>}> */
    public static function files(): iterable
    {
        $file = static fn(string $char, string $name, Encoding $encoding): string => Codecs::for($encoding)->encode(sprintf(self::HEAD, $char, $name));

        yield 'UTF-8 as declared' => [$file('UTF-8', 'Анна', Encoding::Utf8), Encoding::Utf8, []];
        yield 'ANSI with Cyrillic content' => [$file('ANSI', 'Анна Іванівна /Коваль/', Encoding::Windows1251), Encoding::Windows1251, ['KV1101']];
        yield 'ANSI with Latin content' => [$file('ANSI', 'Françoise /Müller/', Encoding::Windows1252), Encoding::Windows1252, ['KV1101']];
        yield 'explicit Windows-1251' => [$file('WINDOWS-1251', 'Петро', Encoding::Windows1251), Encoding::Windows1251, ['KV1101']];
        yield 'CHAR ANSEL is trusted' => [$file('ANSEL', 'Wałęsa', Encoding::Ansel), Encoding::Ansel, []];
        yield 'CHAR ANSEL contradicted by UTF-8' => [$file('ANSEL', 'Анна', Encoding::Utf8), Encoding::Utf8, ['KV1102']];
        yield 'CHAR UNICODE used for UTF-8' => [$file('UNICODE', 'Анна', Encoding::Utf8), Encoding::Utf8, ['KV1102']];
        yield 'CHAR UTF-8 contradicted by CP1251' => [$file('UTF-8', 'Анна Іванівна', Encoding::Windows1251), Encoding::Windows1251, ['KV1102']];
        yield 'CHAR ASCII contradicted by CP1252' => [$file('ASCII', 'Françoise', Encoding::Windows1252), Encoding::Windows1252, ['KV1102']];
        yield 'ASCII-only content keeps CHAR' => [$file('ASCII', 'Anna', Encoding::Ascii), Encoding::Ascii, []];
        yield 'no CHAR, 8-bit content is guessed' => [str_replace("1 CHAR X\n", '', $file('X', 'Анна Іванівна', Encoding::Windows1251)), Encoding::Windows1251, ['KV1103']];
        yield 'UTF-16LE without BOM' => [$file('UNICODE', 'Анна', Encoding::Utf16Le), Encoding::Utf16Le, []];
        yield 'UTF-16BE without BOM' => [$file('UNICODE', 'Анна', Encoding::Utf16Be), Encoding::Utf16Be, []];
        yield 'CHAR MS-DOS' => [$file('MS-DOS', 'Françoise', Encoding::Cp850), Encoding::Cp850, ['KV1101']];
        yield 'CHAR IBM WINDOWS' => [$file('IBM WINDOWS', 'Françoise', Encoding::Windows1252), Encoding::Windows1252, ['KV1101']];
        yield 'CHAR WINDOWS-1252 with Cyrillic content' => [$file('WINDOWS-1252', 'Анна Іванівна /Коваль/', Encoding::Windows1251), Encoding::Windows1251, ['KV1101', 'KV1102']];
        yield 'CHAR WINDOWS-1250' => [$file('WINDOWS-1250', 'Dvořák', Encoding::Windows1250), Encoding::Windows1250, ['KV1101']];
        yield 'ANSI with ASCII-only content' => [$file('ANSI', 'Anna', Encoding::Ascii), Encoding::Windows1252, ['KV1101']];
        yield 'CHAR OEM' => [$file('OEM', 'Françoise', Encoding::Cp437), Encoding::Cp437, ['KV1101']];
        yield 'Mac line endings' => [str_replace("\n", "\r", $file('ANSEL', 'Wałęsa', Encoding::Ansel)), Encoding::Ansel, []];
    }

    /** @param list<string> $codes */
    #[Test]
    #[DataProvider('files')]
    public function detectsEncoding(string $bytes, Encoding $expected, array $codes): void
    {
        $detection = (new EncodingDetector())->detect($bytes);

        self::assertSame($expected, $detection->encoding);
        self::assertSame($codes, array_map(static fn(Diagnostic $d): string => $d->code, $detection->diagnostics));
    }

    #[Test]
    public function bomWinsOverCharAndReportsBom(): void
    {
        $detection = (new EncodingDetector())->detect("\xFF\xFE" . Codecs::for(Encoding::Utf16Le)->encode("0 HEAD\n1 CHAR UNICODE\n"));

        self::assertSame(Encoding::Utf16Le, $detection->encoding);
        self::assertTrue($detection->hasBom);
        self::assertSame('UNICODE', $detection->declaredChar);
    }

    #[Test]
    public function charOutsideHeadIsIgnored(): void
    {
        $detection = (new EncodingDetector())->detect("0 HEAD\n0 @I1@ INDI\n1 CHAR ANSI\n0 TRLR\n");

        self::assertNull($detection->declaredChar);
    }

    #[Test]
    public function explicitEncodingSkipsDetection(): void
    {
        $detection = (new EncodingDetector())->detect("0 HEAD\n1 CHAR ANSEL\n", Encoding::Windows1251);

        self::assertSame(Encoding::Windows1251, $detection->encoding);
        self::assertSame([], $detection->diagnostics);
    }
}

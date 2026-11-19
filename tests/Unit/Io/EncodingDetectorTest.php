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
        self::assertFalse($detection->hasBom);
        self::assertSame($codes, array_map(static fn(Diagnostic $d): string => $d->code, $detection->diagnostics));
        foreach ($detection->diagnostics as $diagnostic) {
            // CHAR is on line 4 of the file, and only KV1103 without CHAR has no line.
            self::assertSame($detection->declaredChar === null ? null : 4, $diagnostic->line);
            self::assertSame($detection->declaredChar === null ? null : 'HEAD/CHAR', $diagnostic->path);
        }
    }

    /** @return iterable<string, array{string, Encoding}> */
    public static function charNames(): iterable
    {
        $names = [
            'utf-8' => Encoding::Utf8, 'us-ascii' => Encoding::Ascii, 'ascii' => Encoding::Ascii, 'Ansel' => Encoding::Ansel,
            'ansi' => Encoding::Windows1252, 'cp1252' => Encoding::Windows1252, 'latin-1' => Encoding::Windows1252,
            'ISO-8859-1' => Encoding::Windows1252, 'ISO 8859' => Encoding::Windows1252, 'Windows' => Encoding::Windows1252,
            'IBM WINDOWS' => Encoding::Windows1252, 'windows-1252' => Encoding::Windows1252,
            'windows-1251' => Encoding::Windows1251, 'CP1251' => Encoding::Windows1251, 'WIN1251' => Encoding::Windows1251, 'Cyrillic' => Encoding::Windows1251,
            'windows-1250' => Encoding::Windows1250, 'CP1250' => Encoding::Windows1250, 'WIN1250' => Encoding::Windows1250,
            'IBMPC' => Encoding::Cp437, 'IBM' => Encoding::Cp437, 'CP437' => Encoding::Cp437, 'IBM437' => Encoding::Cp437, 'OEM' => Encoding::Cp437,
            'CP850' => Encoding::Cp850, 'IBM850' => Encoding::Cp850, 'MS-DOS' => Encoding::Cp850, 'IBM_DOS' => Encoding::Cp850,
            'Macintosh' => Encoding::MacRoman, 'MacRoman' => Encoding::MacRoman, 'MAC' => Encoding::MacRoman,
            'UNICODE' => Encoding::Utf8, 'UTF-16' => Encoding::Utf8, 'something' => Encoding::Utf8,
        ];
        foreach ($names as $name => $encoding) {
            yield $name => [$name, $encoding];
        }
    }

    /** On ASCII-only content the declared name decides. */
    #[Test]
    #[DataProvider('charNames')]
    public function charNamesAndTheirSpellings(string $char, Encoding $expected): void
    {
        $detection = (new EncodingDetector())->detect(sprintf(self::HEAD, $char, 'Anna'));

        self::assertSame($expected, $detection->encoding);
        self::assertSame($char, $detection->declaredChar);
        $standard = in_array(strtoupper($char), ['ANSEL', 'ASCII', 'UNICODE', 'UTF-8'], true);
        self::assertSame($standard ? [] : ['KV1101'], array_map(static fn(Diagnostic $d): string => $d->code, $detection->diagnostics));
    }

    #[Test]
    public function aUtf8SequenceCutByTheSampleIsNoReasonToGuess(): void
    {
        // A two-byte letter straddles the end of the sample.
        $head = "0 HEAD\n1 CHAR UTF-8\n0 @N1@ NOTE ";
        $bytes = $head . str_repeat('a', EncodingDetector::SAMPLE_SIZE - 1 - strlen($head)) . "Ґ\n0 TRLR\n";
        self::assertSame("\xD2", $bytes[EncodingDetector::SAMPLE_SIZE - 1]);

        self::assertSame(Encoding::Utf8, (new EncodingDetector())->detect($bytes)->encoding);
    }

    #[Test]
    public function aZeroByteInsideAFileIsNoUtf16(): void
    {
        self::assertSame(Encoding::Utf8, (new EncodingDetector())->detect("0 HEAD\n1 NOTE a\x00b\n0 TRLR\n")->encoding);
    }

    #[Test]
    public function dialectsAreCorrectedUnlessTurnedOff(): void
    {
        $bytes = Codecs::for(Encoding::Windows1252)->encode("0 HEAD\n1 SOUR BROSKEEP\n2 VERS 6.0\n1 CHAR IBMPC\n0 @I1@ INDI\n1 NAME Françoise\n0 TRLR\n");

        $detection = (new EncodingDetector())->detect($bytes);
        self::assertSame(Encoding::Windows1252, $detection->encoding);
        self::assertFalse($detection->hasBom);
        self::assertSame(Encoding::Cp437, (new EncodingDetector())->detect($bytes, dialects: false)->encoding);
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

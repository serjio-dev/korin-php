<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Writer;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\WriteOptions;
use Korin\Gedcom\Writer\ConcSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    private const FILE_551 = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NOTE short\n0 TRLR\n";

    #[Test]
    public function splitIsGreedyAndAvoidsSpaces(): void
    {
        $length = static fn(string $s): int => (int) preg_match_all('/./su', $s);

        self::assertSame(['abcdef'], ConcSplitter::split('abcdef', 6, 3, $length));
        self::assertSame(['abcd', 'efg', 'h'], ConcSplitter::split('abcdefgh', 4, 3, $length));
        // "ab cd ef": a split at 3 or 4 would put a space at the edge of a line.
        self::assertSame(['a', 'b c', 'd e', 'f'], ConcSplitter::split('ab cd ef', 3, 3, $length));
        // Escapes and grapheme clusters are never cut.
        self::assertSame(['a', '@@b', '@#DJULIAN@'], ConcSplitter::split('a@@b@#DJULIAN@', 2, 3, $length));
        self::assertSame(["e\u{301}", "e\u{301}"], ConcSplitter::split("e\u{301}e\u{301}", 1, 1, $length));
        // Only spaces: no non-space split exists, so the hard limit wins.
        self::assertSame(['  ', '  '], ConcSplitter::split('    ', 2, 2, $length));
    }

    /**
     * @return iterable<string, array{non-empty-string, int}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n", 40];
        yield 'CRLF' => ["\r\n", 40];
        yield 'default limit' => ["\n", WriteOptions::MAX_LINE_LENGTH_5];
    }

    /** @param non-empty-string $lineEnding */
    #[Test]
    #[DataProvider('lineEndings')]
    public function longValuesAreSplitIntoConcLines(string $lineEnding, int $limit): void
    {
        $doc = Gedcom::parse(str_replace("\n", $lineEnding, self::FILE_551));
        $text = rtrim(str_repeat('Коваль-Ковальчук @ Тернопіль, ', 20)) . "\n" . rtrim(str_repeat('друга частина ', 30));
        $doc->find('@I1@/NOTE')?->setValue($text);

        $bytes = Gedcom::serialize($doc, (new WriteOptions())->withMaxLineLength($limit));
        $lines = explode($lineEnding, rtrim($bytes, $lineEnding));

        foreach ($lines as $line) {
            self::assertLessThanOrEqual($limit, (int) preg_match_all("/./su", $line . $lineEnding), $line);
            self::assertStringEndsNotWith(' ', $line);
            self::assertDoesNotMatchRegularExpression('/^\d+ CONC  /', $line);
        }
        self::assertMatchesRegularExpression('/^2 CONC /m', $bytes);
        self::assertSame($text, Gedcom::parse($bytes)->find('@I1@/NOTE')?->value());
        self::assertStringStartsWith("0 HEAD{$lineEnding}1 GEDC{$lineEnding}", $bytes);
    }

    #[Test]
    public function longContinuationGetsSiblingConcLines(): void
    {
        $doc = Gedcom::parse(self::FILE_551);
        $doc->find('@I1@/NOTE')?->setValue("first\n" . str_repeat('x', 30));

        self::assertSame(
            "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NOTE first\n2 CONT xxxxxxxxxx\n2 CONC xxxxxxxxxx\n2 CONC xxxxxxxxxx\n0 TRLR\n",
            Gedcom::serialize($doc, (new WriteOptions())->withMaxLineLength(18)),
        );
    }

    #[Test]
    public function unterminatedLastLineStaysUnterminatedWhenSplit(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n0 TRLR\n1 _X old");
        $doc->find('TRLR/_X')?->setValue('abcdefghij');

        self::assertStringEndsWith("\n1 _X abcde\n2 CONC fgh\n2 CONC ij", Gedcom::serialize($doc, (new WriteOptions())->withMaxLineLength(11)));
    }

    #[Test]
    public function limitCountsBytesInSingleByteEncodings(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR ANSEL\n0 @I1@ INDI\n1 NOTE x\n0 TRLR\n", (new ReadOptions())->withEncoding(Encoding::Ansel));
        $doc->find('@I1@/NOTE')?->setValue('ééééé');

        // Each "é" is two ANSEL bytes: a combining acute accent and "e".
        self::assertStringContainsString("1 NOTE \xE2e\xE2e\n2 CONC \xE2e\xE2e\n2 CONC \xE2e\n", Gedcom::serialize($doc, (new WriteOptions())->withMaxLineLength(12)));
    }

    #[Test]
    public function version7IsNeverSplit(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NOTE x\n0 TRLR\n");
        $doc->find('@I1@/NOTE')?->setValue(str_repeat('y', 300));

        self::assertStringContainsString("\n1 NOTE " . str_repeat('y', 300) . "\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function unchangedLongLinesAreKept(): void
    {
        $bytes = str_replace('short', str_repeat('z', 400), self::FILE_551);

        self::assertSame($bytes, Gedcom::serialize(Gedcom::parse($bytes)));
    }

    #[Test]
    public function normalizeRewritesEveryLineWithOneTerminator(): void
    {
        $doc = Gedcom::parse("0 HEAD\r\n1 GEDC\n2 VERS 5.5.1\r\n1 CHAR UTF-8\r\nnot a line\r\n\r\n0 @I1@ INDI\r\n1 NOTE " . str_repeat('z', 300) . "\r\n0 TRLR");

        self::assertSame(
            "0 HEAD\r\n1 GEDC\r\n2 VERS 5.5.1\r\n1 CHAR UTF-8\r\nnot a line\r\n\r\n0 @I1@ INDI\r\n1 NOTE " . str_repeat('z', 246) . "\r\n2 CONC " . str_repeat('z', 54) . "\r\n0 TRLR\r\n",
            Gedcom::serialize($doc, WriteOptions::normalize()),
        );
        self::assertSame(
            "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n",
            substr(Gedcom::serialize($doc, WriteOptions::normalize()->withLineEnding("\n")), 0, 27),
        );
    }

    #[Test]
    public function normalizeKeepsOnlyLinesAlreadyInTheirNormalForm(): void
    {
        $long = str_repeat('z', 260);
        $bytes = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0  @I1@ INDI\n1  SEX F\n1 NAME Ann\xFF\n1 NOTE {$long}\r\n1 _X\n1 _Y v\n0 @I2@   INDI\n0 TRLR\n";
        $doc = Gedcom::parse($bytes, new ReadOptions(encoding: Encoding::Utf8));

        self::assertSame(
            "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 SEX F\n1 NAME Ann\u{FFFD}\n1 NOTE " . substr($long, 0, 247)
                . "\n2 CONC " . substr($long, 247) . "\n1 _X\n1 _Y v\n0 @I2@ INDI\n0 TRLR\n",
            Gedcom::serialize($doc, WriteOptions::normalize()),
        );
        self::assertStringStartsWith(
            "0 HEAD\r\n1 GEDC\r\n2 VERS 5.5.1\r\n1 CHAR UTF-8\r\n0 @I1@ INDI\r\n1 SEX F\r\n",
            Gedcom::serialize($doc, WriteOptions::normalize()->withLineEnding("\r\n")),
        );
        // A line of exactly the limit is kept; 7.0 has no limit.
        $exact = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @N1@ NOTE " . str_repeat('y', 242) . "\n0 TRLR\n");
        self::assertSame(Gedcom::serialize($exact), Gedcom::serialize($exact, WriteOptions::normalize()));
        $seven = "\xEF\xBB\xBF0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NOTE " . str_repeat('z', 300) . "\n0 TRLR\n";
        self::assertSame($seven, Gedcom::serialize(Gedcom::parse($seven), WriteOptions::normalize()));
    }

    #[Test]
    public function anotherEncodingReencodesUnchangedLines(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Анна /Коваль/\r\n0 TRLR");

        $bytes = Gedcom::serialize($doc, WriteOptions::preserve()->withEncoding(Encoding::Utf16Le));

        // UTF-16 gets a BOM; the terminators, and the missing last one, are kept.
        self::assertSame("\xFF\xFE" . mb_convert_encoding("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Анна /Коваль/\r\n0 TRLR", 'UTF-16LE', 'UTF-8'), $bytes);
        self::assertSame($bytes, Gedcom::serialize(Gedcom::parse($bytes)));
    }

    /** @return iterable<string, array{string, Encoding, bool}> */
    public static function boms(): iterable
    {
        yield '7.0' => ['7.0', Encoding::Utf8, true];
        yield '5.5.5' => ['5.5.5', Encoding::Utf8, true];
        yield '5.5.1 in UTF-8' => ['5.5.1', Encoding::Utf8, false];
        yield '5.5 in UTF-16' => ['5.5', Encoding::Utf16Be, true];
        yield 'ANSEL has none' => ['5.5.1', Encoding::Ansel, false];
    }

    #[Test]
    #[DataProvider('boms')]
    public function normalizedFilesGetTheBomOfTheirVersion(string $version, Encoding $encoding, bool $bom): void
    {
        $doc = Gedcom::parse("\u{FEFF}0 HEAD\n1 GEDC\n2 VERS {$version}\n0 TRLR\n");
        $bytes = Gedcom::serialize($doc, WriteOptions::normalize()->withEncoding($encoding));

        self::assertSame($bom, $encoding->bom() !== '' && str_starts_with($bytes, $encoding->bom()));
        self::assertFalse(str_starts_with(Gedcom::serialize($doc, WriteOptions::normalize()->withBom(false)), "\xEF"));
        self::assertTrue(str_starts_with(Gedcom::serialize($doc), "\xEF\xBB\xBF"), 'preserve keeps the BOM of the source');
    }
}

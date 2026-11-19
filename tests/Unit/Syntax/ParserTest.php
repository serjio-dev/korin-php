<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Syntax;

use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\Parser;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private const HEAD = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n";

    /** @return iterable<string, array{string, list<string>, Version}> */
    public static function files(): iterable
    {
        yield 'first record not HEAD' => ["0 @I1@ INDI\n" . self::HEAD . "0 TRLR\n", ['KV1004 error line 1: The first record is not HEAD'], Version::V5_5_1];
        yield 'no TRLR' => [self::HEAD . "0 @I1@ INDI\n", ['KV1005 error: TRLR is missing'], Version::V5_5_1];
        yield 'records after TRLR' => [self::HEAD . "0 TRLR\n0 @I1@ INDI\n", ['KV1006 warning line 5: Records after TRLR'], Version::V5_5_1];
        yield 'no VERS' => ["0 HEAD\n1 SOUR X\n0 TRLR\n", ['KV1007 warning line 1 (HEAD/GEDC/VERS): HEAD.GEDC.VERS is missing; read as 5.5.1'], Version::V5_5_1];
        yield 'unknown version' => ["0 HEAD\n1 GEDC\n2 VERS 9.1\n0 TRLR\n", ['KV1008 warning line 3 (HEAD/GEDC/VERS): Unknown GEDCOM version; read as 5.5.1'], Version::V5_5_1];
        yield '5.5 draft' => ["0 HEAD\n1 GEDC\n2 VERS 5.4\n0 TRLR\n", ['KV1009 info line 3 (HEAD/GEDC/VERS): A 5.5 draft is read with the 5.5 profile'], Version::V5_5];
        yield '5.5 with spaces' => ["0 HEAD\n1 GEDC\n2 VERS  5.5 \n0 TRLR\n", [], Version::V5_5];
        yield '4.0' => ["0 HEAD\n1 GEDC\n2 VERS 4.0\n0 TRLR\n", ['KV1009 info line 3 (HEAD/GEDC/VERS): GEDCOM 4.0 is read with the 5.5 profile'], Version::V4_0];
        yield 'level jump' => [self::HEAD . "0 @I1@ INDI\n1 NAME A\n3 DEEP\n0 TRLR\n", ['KV1002 error line 6: Level 3 does not follow level 1'], Version::V5_5_1];
        yield 'level jump from a record' => [self::HEAD . "0 @I1@ INDI\n2 DEEP\n0 TRLR\n", ['KV1002 error line 5: Level 2 does not follow level 0'], Version::V5_5_1];
        yield 'not a line' => [self::HEAD . "not a line\n0 TRLR\n", ['KV1001 warning line 4: Line is not a valid GEDCOM line and is kept verbatim'], Version::V5_5_1];
    }

    /** @param list<string> $diagnostics */
    #[Test]
    #[DataProvider('files')]
    public function fileChecksReportWhatIsWrong(string $bytes, array $diagnostics, Version $version): void
    {
        $document = Gedcom::parse($bytes);

        self::assertSame($diagnostics, array_map(strval(...), $document->diagnostics()));
        self::assertSame($version, $document->version());
    }

    #[Test]
    public function strictModeThrowsOnErrorsOnly(): void
    {
        $strict = (new ReadOptions())->withStrict();
        self::assertCount(1, Gedcom::parse(self::HEAD . "0 TRLR\n0 @I1@ INDI\n", $strict)->diagnostics());

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('KV1005');
        Gedcom::parse(self::HEAD . "0 @I1@ INDI\n", $strict);
    }

    #[Test]
    public function limitsAtTheirBoundaries(): void
    {
        $line = '1 NOTE ' . str_repeat('x', 93);
        $deep = self::HEAD . "0 @I1@ INDI\n1 A\n2 B\n3 C\n0 TRLR\n";
        self::assertSame(100, strlen($line));
        self::assertNotNull(Gedcom::parse(self::HEAD . "0 @N1@ NOTE\n{$line}\n0 TRLR\n", (new ReadOptions())->withLimits(4, 100))->find('@N1@/NOTE'));
        self::assertNotNull(Gedcom::parse($deep, (new ReadOptions())->withLimits(4, 100))->find('@I1@/A/B/C'));

        $this->expectException(ParseException::class);
        Gedcom::parse($deep, (new ReadOptions())->withLimits(3, 100));
    }

    #[Test]
    public function aLineOverTheLimitIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Line 5 exceeds the limit of 100 bytes');

        Gedcom::parse(self::HEAD . "0 @N1@ NOTE\n1 NOTE " . str_repeat('x', 94) . "\n0 TRLR\n", (new ReadOptions())->withLimits(4, 100));
    }

    #[Test]
    public function theDominantLineTerminatorIsTheDocumentsOne(): void
    {
        self::assertSame("\r\n", Gedcom::parse("0 HEAD\n1 GEDC\r\n2 VERS 7.0\r\n0 TRLR\n0 @I1@ INDI\r\n")->lineEnding());
        self::assertSame("\n", Gedcom::parse("0 HEAD\r1 GEDC\n2 VERS 7.0\n0 TRLR")->lineEnding());
    }

    #[Test]
    public function declaredVersionCorrectsDialectsUnlessTurnedOff(): void
    {
        $head = Gedcom::parse("0 HEAD\n1 SOUR PAF\n2 VERS 5.2.18.0\n1 GEDC\n2 VERS 5.5\n0 TRLR\n")->head();
        self::assertNotNull($head);

        self::assertSame(Version::V5_5_1, Parser::declaredVersion($head));
        self::assertSame(Version::V5_5, Parser::declaredVersion($head, dialects: false));
        self::assertNull(Parser::declaredVersion(Gedcom::parse("0 HEAD\n1 GEDC\n0 TRLR\n")->head() ?? $head));
    }
}

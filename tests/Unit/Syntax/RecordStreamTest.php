<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Syntax;

use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Syntax\RecordReader;
use Korin\Gedcom\Syntax\RecordStream;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordStreamTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        yield 'LF' => ["0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Анна /Коваль/\n1 NOTE a\n2 CONT b\n0 TRLR\n"];
        yield 'CR only' => ["0 HEAD\r1 GEDC\r2 VERS 5.5.1\r0 @I1@ INDI\r1 NAME A\r0 TRLR\r"];
        yield 'CRLF, no final terminator' => ["0 HEAD\r\n1 GEDC\r\n2 VERS 7.0\r\n0 @I1@ INDI\r\n0 TRLR"];
        yield 'UTF-8 BOM' => ["\xEF\xBB\xBF0 HEAD\n1 GEDC\n2 VERS 7.0\n0 TRLR\n"];
        yield 'UTF-16LE BOM' => ["\xFF\xFE" . mb_convert_encoding("0 HEAD\r\n1 CHAR UNICODE\r\n0 @I1@ INDI\r\n1 NAME Анна\r\n0 TRLR\r\n", 'UTF-16LE', 'UTF-8')];
        yield 'UTF-16BE without BOM' => [mb_convert_encoding("0 HEAD\n1 CHAR UNICODE\n0 @I1@ INDI\n1 NAME Анна\n0 TRLR", 'UTF-16BE', 'UTF-8')];
        yield 'Windows-1251' => ["0 HEAD\n1 CHAR ANSI\n0 @I1@ INDI\n1 NAME \xC0\xED\xED\xE0 /\xCA\xEE\xE2\xE0\xEB\xFC/\n0 TRLR\n"];
        yield 'lines before HEAD' => ["junk\n\nmore junk\n0 HEAD\n1 GEDC\n2 VERS 7.0\n0 TRLR\n"];
        yield 'first line at level 1' => ["1 FOO\n2 BAR\n1 BAZ\n0 HEAD\n0 TRLR"];
        yield 'level 00, records after TRLR' => ["0 HEAD\n1 CHAR UTF-8\n0 @I1@ INDI\n  00 TRLR\n0 @I2@ INDI\n"];
        yield 'invalid line and level jump inside a record' => ["0 HEAD\n0 @I1@ INDI\nnot a line\n3 DEEP\n0 TRLR\n"];
        yield 'invalid UTF-8 after the sample' => ["0 HEAD\n1 CHAR UTF-8\n" . str_repeat("0 @N1@ NOTE x\n", 6000) . "0 @N2@ NOTE \xC3\x28\n0 TRLR\n"];
        yield 'no HEAD, unknown version' => ["0 @I1@ INDI\n0 HEAD\n1 GEDC\n2 VERS 9.9\n"];
        yield 'empty' => [''];
        yield 'mixed terminators, CRLF the most' => ["0 HEAD\n1 GEDC\r\n2 VERS 7.0\r\n0 @I1@ INDI\r\n0 TRLR\n"];
        yield 'CR, then LF the most' => ["0 HEAD\r1 GEDC\n2 VERS 7.0\n0 TRLR\n"];
    }

    #[Test]
    #[DataProvider('files')]
    public function recordsAreTheRecordsOfTheWholeFile(string $bytes): void
    {
        $document = Gedcom::parse($bytes);
        $expected = [];
        foreach ($document->records() as $record) {
            self::dump($record, $expected);
        }
        $diagnostics = array_map(strval(...), $document->diagnostics());
        sort($diagnostics);

        foreach ([1, 2, 3, 7, 65536] as $chunkSize) {
            $stream = new RecordStream($this->handle($bytes), chunkSize: $chunkSize);
            $nodes = $streamed = [];
            $out = '';
            foreach ($stream as $record) {
                self::dump($record->node(), $nodes);
                self::assertSame($record->node()->line(), $record->line());
                $out .= Gedcom::serialize($record->document());
                array_push($streamed, ...array_map(strval(...), $record->document()->diagnostics()));
            }
            array_push($streamed, ...array_map(strval(...), $stream->diagnostics()));
            sort($streamed);

            self::assertSame($bytes, $out, "Chunks of {$chunkSize}: records written in preserve mode are the file");
            self::assertSame($expected, $nodes, "Chunks of {$chunkSize}: nodes");
            self::assertSame($diagnostics, $streamed, "Chunks of {$chunkSize}: diagnostics");
            self::assertSame($document->encoding(), $stream->encoding());
            self::assertSame($document->hasBom(), $stream->hasBom());
        }
    }

    #[Test]
    public function aFileOfOnlyAByteOrderMarkHasNoRecordButTheStreamHasTheMark(): void
    {
        foreach (["\xEF\xBB\xBF", "\xFF\xFE"] as $bytes) {
            $stream = new RecordStream($this->handle($bytes));

            self::assertSame([], iterator_to_array($stream));
            self::assertTrue($stream->hasBom());
            self::assertSame($bytes, $stream->encoding()->bom());
        }
    }

    #[Test]
    #[DataProvider('files')]
    public function readerSplitsRecordsAtTheSameBytesWhateverTheChunks(string $bytes): void
    {
        $document = Gedcom::parse($bytes);
        $lines = array_map(static fn(Node $record): ?int => $record->line(), $document->records());

        foreach ([1, 2, 3, 7, 65536] as $chunkSize) {
            $reader = new RecordReader($this->handle($bytes), new ReadOptions(), $chunkSize);
            $records = iterator_to_array($reader->records(), false);
            $offset = $reader->detection()->hasBom ? strlen($reader->detection()->encoding->bom()) : 0;
            foreach ($records as [$record, $start]) {
                self::assertSame($offset, $start, "Chunks of {$chunkSize}: offset");
                $offset += strlen($record);
            }

            self::assertSame($bytes, ($reader->detection()->hasBom ? $reader->detection()->encoding->bom() : '') . implode('', array_column($records, 0)), "Chunks of {$chunkSize}: bytes");
            self::assertSame($lines, array_slice(array_column($records, 2), count($records) - count($lines)), "Chunks of {$chunkSize}: lines");
            self::assertSame($document->lineEnding(), $reader->lineEnding(), "Chunks of {$chunkSize}: line ending");
        }
    }

    #[Test]
    public function versionComesFromHead(): void
    {
        $stream = Gedcom::stream($this->handle("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NOTE @@me\n0 TRLR\n"));
        $versions = [];
        foreach ($stream as $record) {
            $versions[] = $record->document()->version();
        }

        self::assertSame([Version::V7_0, Version::V7_0, Version::V7_0], $versions);
        self::assertSame(Version::V7_0, $stream->version());
    }

    #[Test]
    public function recordIsEditedAndWrittenOnItsOwn(): void
    {
        $out = '';
        foreach (Gedcom::stream($this->handle("0 HEAD\r\n1 CHAR UTF-8\r\n0 @I1@ INDI\r\n1 NAME Anna /Koval/\r\n0 TRLR\r\n")) as $record) {
            if ($record->xref() === '@I1@') {
                $record->node()->first('NAME')?->setValue('Anna /Kovalova/');
            }
            $out .= Gedcom::serialize($record->document());
        }

        self::assertSame("0 HEAD\r\n1 CHAR UTF-8\r\n0 @I1@ INDI\r\n1 NAME Anna /Kovalova/\r\n0 TRLR\r\n", $out);
    }

    #[Test]
    public function explicitEncodingIsUsed(): void
    {
        $stream = Gedcom::stream($this->handle("0 HEAD\n0 @I1@ INDI\n1 NAME \xC0\xED\xED\xE0\n0 TRLR\n"), (new ReadOptions())->withEncoding(Encoding::Windows1251));
        $names = [];
        foreach ($stream as $record) {
            $names[] = $record->node()->first('NAME')?->value();
        }

        self::assertSame([null, 'Анна', null], $names);
    }

    #[Test]
    public function strictModeThrowsOnTheFirstError(): void
    {
        $this->expectException(ParseException::class);

        foreach (Gedcom::stream($this->handle("0 HEAD\n0 @I1@ INDI\n2 DEEP\n0 TRLR\n"), (new ReadOptions())->withStrict()) as $record) {
            self::assertNotSame('TRLR', $record->tag());
        }
    }

    #[Test]
    public function lineLongerThanTheLimitIsNotBuffered(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Line 2 exceeds the limit of 100 bytes');

        $stream = new RecordStream($this->handle("0 HEAD\n1 NOTE " . str_repeat('x', 10_000)), (new ReadOptions())->withLimits(100, 100), chunkSize: 16);
        foreach ($stream as $record) {
            self::assertSame('HEAD', $record->tag());
        }
    }

    #[Test]
    public function streamIsReadOnce(): void
    {
        $stream = Gedcom::stream($this->handle("0 HEAD\n0 TRLR\n"));
        iterator_to_array($stream);

        $this->expectException(\LogicException::class);
        iterator_to_array($stream);
    }

    #[Test]
    public function handleIsLeftOpenAndPathIsClosed(): void
    {
        $handle = $this->handle("0 HEAD\n0 TRLR\n");
        iterator_to_array(Gedcom::stream($handle));
        self::assertTrue(is_resource($handle));

        $path = tempnam(sys_get_temp_dir(), 'korin');
        file_put_contents($path, "0 HEAD\n0 TRLR\n");
        self::assertSame(['HEAD', 'TRLR'], array_map(static fn(\Korin\Gedcom\Syntax\Record $r): string => $r->tag(), iterator_to_array(Gedcom::stream($path), false)));
        unlink($path);
    }

    #[Test]
    public function missingFileThrows(): void
    {
        $this->expectException(IoException::class);

        Gedcom::stream(sys_get_temp_dir() . '/korin-missing.ged');
    }

    /** @return resource */
    private function handle(string $bytes): mixed
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertNotFalse($handle);
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }

    /** @param list<string> $out */
    private static function dump(Node $node, array &$out): void
    {
        $out[] = implode(' ', [$node->line(), $node->level(), $node->xref(), $node->tag(), json_encode($node->value()), $node->pointer()]);
        foreach ($node->children() as $child) {
            self::dump($child, $out);
        }
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Syntax;

use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Exception\ReadOnlyException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexedDocumentTest extends TestCase
{
    private const FILE = "0 HEAD\r\n1 GEDC\r\n2 VERS 7.0\r\n0 @I1@ INDI\r\n1 NAME Anna /Koval/\r\n1 NOTE @@me\r\n1 FAMS @F1@\r\n0 @F1@ FAM\r\n1 WIFE @I1@\r\n0 TRLR\r\n";

    #[Test]
    #[DataProviderExternal(RecordStreamTest::class, 'files')]
    public function recordsAreTheRecordsOfTheWholeFile(string $bytes): void
    {
        $document = Gedcom::parse($bytes);
        $indexed = Gedcom::open($this->handle($bytes));
        $expected = $nodes = [];
        foreach ($document->records() as $record) {
            self::dump($record, $expected);
        }
        $out = '';
        $diagnostics = array_map(strval(...), $indexed->diagnostics());
        foreach ($indexed->records() as $record) {
            self::dump($record, $nodes);
            $out .= Gedcom::serialize($record->document());
            array_push($diagnostics, ...array_map(strval(...), $record->document()->diagnostics()));
        }
        $wanted = array_map(strval(...), $document->diagnostics());
        sort($wanted);
        sort($diagnostics);

        self::assertSame($expected, $nodes);
        self::assertSame($bytes, $out);
        self::assertSame($wanted, $diagnostics);
        self::assertCount(count($document->records()), $indexed);
        self::assertSame($document->version(), $indexed->version());
        self::assertSame($document->encoding(), $indexed->encoding());
        self::assertSame($document->hasBom(), $indexed->hasBom());
        self::assertSame($document->lineEnding(), $indexed->lineEnding());
        self::assertSame($bytes, Gedcom::serialize($indexed->load()));
        self::assertSame($document->head()?->line(), $indexed->head()?->line());
    }

    #[Test]
    public function recordsAreFoundByXrefAndPath(): void
    {
        $indexed = Gedcom::open($this->handle(self::FILE));

        self::assertSame(4, $indexed->record('@I1@')?->line());
        self::assertSame('@me', $indexed->find('@I1@/NOTE')?->value());
        self::assertSame('@I1@', $indexed->find('@F1@/WIFE')?->pointer());
        self::assertSame('7.0', $indexed->find('HEAD/GEDC/VERS')?->value());
        self::assertSame(10, $indexed->find('TRLR')?->line());
        self::assertNull($indexed->record('@X9@'));
        self::assertNull($indexed->find('@X9@/NAME'));
        self::assertSame(['@I1@' => 1, '@F1@' => 2], $indexed->xrefs());
        self::assertSame(['HEAD', 'INDI', 'FAM', 'TRLR'], array_map($indexed->tagAt(...), range(0, 3)));
    }

    #[Test]
    public function recordsAreReadOnlyAndTheirCopiesCanBeEdited(): void
    {
        $record = Gedcom::open($this->handle(self::FILE))->record('@I1@');
        self::assertInstanceOf(Node::class, $record);
        self::assertTrue($record->document()->isReadOnly());

        $copy = $record->document()->copy();
        $copy->find('@I1@/NAME')?->setValue('Anna /Kovalova/');
        self::assertSame("0 @I1@ INDI\r\n1 NAME Anna /Kovalova/\r\n1 NOTE @@me\r\n1 FAMS @F1@\r\n", Gedcom::serialize($copy));

        $this->expectException(ReadOnlyException::class);
        $record->first('NAME')?->setValue('Hanna');
    }

    #[Test]
    public function recentlyUsedRecordsAreCached(): void
    {
        $indexed = new IndexedDocument($this->handle(self::FILE), cacheSize: 2);
        $i1 = $indexed->record('@I1@')?->document();
        $indexed->record('@F1@');

        self::assertSame($i1, $indexed->record('@I1@')?->document());
        $indexed->head();
        $indexed->find('TRLR');
        self::assertNotSame($i1, $indexed->record('@I1@')?->document(), 'evicted');
    }

    #[Test]
    public function handleIsReadFromItsPosition(): void
    {
        $handle = $this->handle('prefix' . self::FILE);
        fseek($handle, 6);
        $indexed = Gedcom::open($handle);

        self::assertSame('Anna /Koval/', $indexed->find('@I1@/NAME')?->value());
        self::assertSame(self::FILE, Gedcom::serialize($indexed->load()));
        self::assertSame(Version::V7_0, $indexed->version());
        $out = $this->handle('');
        $indexed->copyTo($out);
        rewind($out);
        self::assertSame(self::FILE, stream_get_contents($out));
    }

    #[Test]
    public function recordNumbersOutsideTheIndexThrow(): void
    {
        $indexed = Gedcom::open($this->handle(self::FILE));
        $count = count($indexed);
        self::assertSame('TRLR', $indexed->tagAt($count - 1));

        foreach ([-1, $count] as $i) {
            try {
                $indexed->recordAt($i);
                self::fail("Record {$i}");
            } catch (\OutOfRangeException $e) {
                self::assertSame("No record {$i}", $e->getMessage());
            }
        }
    }

    #[Test]
    public function theVersionOfTheOptionsWins(): void
    {
        $options = (new ReadOptions())->withVersion(Version::V5_5);

        self::assertSame(Version::V5_5, Gedcom::open($this->handle(self::FILE), $options)->version());
        self::assertSame(Version::V5_5, Gedcom::open($this->handle("0 @I1@ INDI\n0 TRLR\n"), $options)->version());
        self::assertSame(Version::V5_5_1, Gedcom::open($this->handle("0 @I1@ INDI\n0 TRLR\n"))->version());
    }

    #[Test]
    public function trlrIsTheFirstTrailerAsInADocument(): void
    {
        $bytes = "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 TRLR\n0 @I1@ INDI\n0 TRLR\n";

        self::assertSame(4, Gedcom::open($this->handle($bytes))->find('TRLR')?->line());
        self::assertSame(4, Gedcom::parse($bytes)->find('TRLR')?->line());
    }

    #[Test]
    public function streamThatCannotSeekIsCopied(): void
    {
        stream_wrapper_register('korin-pipe', PipeStream::class);
        try {
            PipeStream::$bytes = self::FILE;
            $handle = fopen('korin-pipe://in', 'rb');
            self::assertNotFalse($handle);

            self::assertSame('Anna /Koval/', Gedcom::open($handle)->find('@I1@/NAME')?->value());
        } finally {
            stream_wrapper_unregister('korin-pipe');
        }
    }

    #[Test]
    public function fileChangedAfterOpeningThrows(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'korin');
        file_put_contents($path, self::FILE);
        $indexed = Gedcom::open($path);
        file_put_contents($path, '0 HEAD');

        try {
            $this->expectException(IoException::class);
            $indexed->record('@F1@');
        } finally {
            unset($indexed);
            unlink($path);
        }
    }

    #[Test]
    public function strictModeRejectsAnErrorInAnyRecordWhileIndexing(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('KV1002');

        Gedcom::open(self::handle("0 HEAD\n0 @I1@ INDI\n0 @I2@ INDI\n2 DEEP\n0 TRLR\n"), (new ReadOptions())->withStrict());
    }

    #[Test]
    public function lineLongerThanTheLimitIsRejectedWhileIndexing(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Line 3 exceeds the limit of 100 bytes');

        Gedcom::open(self::handle("0 HEAD\n0 @I1@ INDI\n1 NOTE " . str_repeat('x', 1000) . "\n0 TRLR\n"), (new ReadOptions())->withLimits(100, 100));
    }

    #[Test]
    public function closedHandleThrows(): void
    {
        $handle = self::handle(self::FILE);
        $indexed = Gedcom::open($handle);
        fclose($handle);

        $this->expectException(IoException::class);
        $indexed->record('@F1@');
    }

    #[Test]
    public function headIsParsedWithTheDeclaredVersion(): void
    {
        $indexed = Gedcom::open(self::handle("0 HEAD\n1 GEDC\n2 VERS 7.0\n1 NOTE @@a @@b\n0 TRLR\n"));

        self::assertSame(Version::V7_0, $indexed->head()?->document()->version());
        self::assertSame('@a @@b', $indexed->find('HEAD/NOTE')?->value());
    }

    #[Test]
    public function recordsAreParsedTogetherWithTheirLines(): void
    {
        $indexed = Gedcom::open(self::handle(self::FILE));

        self::assertSame(strlen("0 @F1@ FAM\r\n1 WIFE @I1@\r\n"), $indexed->sizeAt(2));
        self::assertSame(strlen("0 TRLR\r\n"), $indexed->sizeAt(3));
        // Records 1 and 3, without the one between them.
        $document = $indexed->recordsAt([1, 3]);
        self::assertTrue($document->isReadOnly());
        self::assertSame(['INDI', 'TRLR'], array_map(static fn(Node $record): string => $record->tag(), $document->records()));
        self::assertSame([4, 5, 6, 7, 10], array_map($document->lineOf(...), [1, 2, 3, 4, 5]));
        self::assertSame('0 TRLR', $document->originalBytes(5)[0] ?? null);
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

/** A stream that can only be read forward, as a pipe. */
final class PipeStream
{
    public static string $bytes = '';

    /** @var resource|null set by PHP for stream wrappers */
    public $context;

    private int $position = 0;

    public function stream_open(): bool
    {
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$bytes, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$bytes);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

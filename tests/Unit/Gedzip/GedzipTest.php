<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Gedzip;

use Korin\Gedcom\Exception\GedzipException;
use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Gedzip\EntryName;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GedzipTest extends TestCase
{
    private const GED = "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @M1@ OBJE\n1 FILE photos/a%20b.jpg\n2 FORM image/jpeg\n0 TRLR\n";

    private string $dir;

    protected function setUp(): void
    {
        // Made first: tearDown() runs for a test skipped here too.
        $this->dir = sys_get_temp_dir() . '/korin-gedzip-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        if (!class_exists(\ZipArchive::class) && $this->name() !== 'withoutZipExtensionGedzipIsRefused') {
            self::markTestSkipped('GEDZIP needs ext-zip');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files() as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    #[Test]
    public function writtenArchiveIsReadBack(): void
    {
        $photo = $this->dir . '/photo.jpg';
        file_put_contents($photo, "\xFF\xD8jpeg");
        $notes = fopen('php://memory', 'w+b');
        self::assertNotFalse($notes);
        fwrite($notes, 'Нотатки');
        rewind($notes);

        Gedcom::writeGedzip(Gedcom::parse(self::GED), $this->dir . '/out.gdz', ['photos/a b.jpg' => $photo, 'notes.txt' => $notes]);
        $archive = Gedcom::readGedzip($this->dir . '/out.gdz');

        self::assertSame(self::GED, Gedcom::serialize($archive->document()));
        self::assertSame(['photos/a b.jpg', 'notes.txt'], $archive->entries());
        self::assertSame([], $archive->missing());
        self::assertSame([], $archive->diagnostics());
        self::assertTrue($archive->has('photos/a%20b.jpg'));
        self::assertFalse($archive->has('https://example.com/notes.txt'));
        self::assertSame("\xFF\xD8jpeg", stream_get_contents($archive->media('photos/a%20b.jpg')));
        self::assertSame(['out.gdz', 'photo.jpg'], array_map(basename(...), $this->files()), 'No temporary file is left');
    }

    #[Test]
    public function withoutZipExtensionGedzipIsRefused(): void
    {
        if (class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is loaded');
        }

        $this->expectExceptionObject(new GedzipException('GEDZIP needs the zip extension (ext-zip)'));
        Gedcom::readGedzip($this->dir . '/in.gdz');
    }

    #[Test]
    public function onlyVersion70IsWritten(): void
    {
        $this->expectException(GedzipException::class);
        Gedcom::writeGedzip(Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 TRLR\n"), $this->dir . '/out.gdz');
    }

    #[Test]
    public function unsafeMediaNamesAreNotWritten(): void
    {
        try {
            Gedcom::writeGedzip(Gedcom::parse(self::GED), $this->dir . '/out.gdz', ['../x.jpg' => __FILE__]);
            self::fail('No exception');
        } catch (GedzipException) {
            self::assertFileDoesNotExist($this->dir . '/out.gdz');
        }
    }

    #[Test]
    public function archiveWithoutGedcomIsRejected(): void
    {
        $this->zip(['other.ged' => self::GED]);

        $this->expectExceptionObject(new GedzipException('The archive has no gedcom.ged'));
        Gedcom::readGedzip($this->dir . '/in.gdz');
    }

    /** Found by fuzzing: the zip stream warned (CRC error) rather than the reader throwing. */
    #[Test]
    public function damagedGedcomIsRejected(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->dir . '/in.gdz', \ZipArchive::CREATE));
        $zip->addFromString('gedcom.ged', self::GED);
        $zip->setCompressionName('gedcom.ged', \ZipArchive::CM_STORE);
        $zip->close();
        $bytes = (string) file_get_contents($this->dir . '/in.gdz');
        file_put_contents($this->dir . '/in.gdz', str_replace('2 VERS 7.0', '2 VERS 7.1', $bytes));

        $this->expectExceptionObject(new GedzipException('gedcom.ged is damaged: CRC error'));
        Gedcom::readGedzip($this->dir . '/in.gdz');
    }

    #[Test]
    public function notAZipIsRejected(): void
    {
        file_put_contents($this->dir . '/in.gdz', self::GED);

        $this->expectException(GedzipException::class);
        Gedcom::readGedzip($this->dir . '/in.gdz');
    }

    #[Test]
    public function highlyCompressedEntryIsRejected(): void
    {
        $this->zip(['gedcom.ged' => self::GED, 'bomb.bin' => str_repeat("\0", 8 * 1_048_576)]);

        $this->expectExceptionObject(new GedzipException('An entry is compressed more than 100 times'));
        Gedcom::readGedzip($this->dir . '/in.gdz');
    }

    #[Test]
    public function archiveOverTheSizeLimitIsRejected(): void
    {
        $this->zip(['gedcom.ged' => self::GED, 'a.txt' => str_repeat('x', 1000)]);

        $this->expectException(GedzipException::class);
        Gedcom::readGedzip($this->dir . '/in.gdz', (new ReadOptions())->withArchiveLimits(500, 100));
    }

    #[Test]
    public function documentOfAnotherVersionIsReported(): void
    {
        $this->zip(['gedcom.ged' => "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 TRLR\n"]);

        self::assertSame(['KV1203'], array_map(static fn(\Korin\Gedcom\Diagnostic $d): string => $d->code, Gedcom::readGedzip($this->dir . '/in.gdz')->diagnostics()));
    }

    #[Test]
    public function missingEntryIsNotOpened(): void
    {
        $this->zip(['gedcom.ged' => self::GED]);
        $archive = Gedcom::readGedzip($this->dir . '/in.gdz');

        self::assertSame(['photos/a%20b.jpg'], $archive->missing());
        $this->expectException(GedzipException::class);
        $archive->media('photos/a%20b.jpg');
    }

    #[Test]
    public function entryIsOpenedAndConvertedThroughZipWrapper(): void
    {
        $ged = "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NAME Анна\n0 @I2@ INDI\n1 NAME Петро\n0 TRLR\n";
        $this->zip(['gedcom.ged' => $ged]);
        $entry = 'zip://' . $this->dir . '/in.gdz#gedcom.ged';

        $document = Gedcom::open($entry);
        // Back and forth on an entry that cannot seek.
        $name = static fn(string $xref): ?string => $document->individual($xref)?->name()?->full();
        self::assertSame(['Петро', 'Анна', 'Петро'], [$name('@I2@'), $name('@I1@'), $name('@I2@')]);

        Gedcom::convertFile($entry, $this->dir . '/out.ged', Version::V5_5_1);
        self::assertSame(Gedcom::serialize(Gedcom::convert(Gedcom::parse($ged), Version::V5_5_1)->document()), file_get_contents($this->dir . '/out.ged'));
    }

    #[Test]
    public function entryOpenedByRelativePathSurvivesChdir(): void
    {
        $this->zip(['gedcom.ged' => "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n0 @I2@ INDI\n0 TRLR\n"]);
        $cwd = (string) getcwd();
        chdir($this->dir);
        try {
            $document = Gedcom::open('zip://in.gdz#gedcom.ged');
            chdir(sys_get_temp_dir());
            $document->record('@I2@');

            self::assertSame('@I1@', $document->record('@I1@')?->xref());
        } finally {
            chdir($cwd);
        }
    }

    #[Test]
    public function entryReplacedAfterOpenIsReported(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows does not let a file that is open be replaced');
        }
        $this->zip(['gedcom.ged' => "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 NAME Анна\n0 @I2@ INDI\n0 TRLR\n"]);
        $document = Gedcom::open('zip://' . $this->dir . '/in.gdz#gedcom.ged');
        $document->record('@I2@');
        unlink($this->dir . '/in.gdz');
        $this->zip(['gedcom.ged' => "0 HEAD\n"]);

        $this->expectExceptionObject(new IoException('The file changed after it was opened'));
        $document->record('@I1@');
    }

    #[Test]
    public function otherPathThatCannotSeekIsCopied(): void
    {
        stream_wrapper_register('korin-noseek', NoSeekStream::class);
        try {
            NoSeekStream::$bytes = "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n0 @I2@ INDI\n0 TRLR\n";
            $document = Gedcom::open('korin-noseek://tree.ged');
            NoSeekStream::$bytes = '';

            self::assertSame('@I1@', $document->record('@I1@')?->xref());
            self::assertSame(1, NoSeekStream::$opened);
        } finally {
            stream_wrapper_unregister('korin-noseek');
        }
    }

    #[Test]
    public function reopenedHandleIsOwned(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertNotFalse($handle);

        $this->expectException(\InvalidArgumentException::class);
        new IndexedDocument($handle, path: 'zip://x.gdz#gedcom.ged');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'plain' => ['a.jpg', true];
        yield 'nested' => ['photos/2020/a b.jpg', true];
        yield 'dots in a name' => ['a..b.jpg', true];
        yield 'parent' => ['../a.jpg', false];
        yield 'parent inside' => ['photos/../../a.jpg', false];
        yield 'dot' => ['./a.jpg', false];
        yield 'absolute' => ['/etc/passwd', false];
        yield 'backslash' => ['photos\a.jpg', false];
        yield 'drive' => ['C:a.jpg', false];
        yield 'empty segment' => ['photos//a.jpg', false];
        yield 'nul' => ["a\0.jpg", false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('names')]
    public function entryNamesAreSafeOrNot(string $name, bool $safe): void
    {
        self::assertSame($safe, EntryName::isSafe($name));
    }

    #[Test]
    public function referencesNameEntries(): void
    {
        self::assertSame('a b.jpg', EntryName::ofReference('a%20b.jpg'));
        self::assertSame('a.jpg', EntryName::ofReference('a.jpg#page=2'));
        self::assertNull(EntryName::ofReference('https://example.com/a.jpg'));
        self::assertNull(EntryName::ofReference('file:///a.jpg'));
        self::assertNull(EntryName::ofReference('/a.jpg'));
        self::assertNull(EntryName::ofReference(''));
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->dir . '/*');

        return $files === false ? [] : $files;
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->dir . '/in.gdz', \ZipArchive::CREATE));
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
    }
}

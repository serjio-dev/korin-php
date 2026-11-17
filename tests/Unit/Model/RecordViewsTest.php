<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Model\MediaFile;
use Korin\Gedcom\Model\MediaObject;
use Korin\Gedcom\Model\Repository;
use Korin\Gedcom\Model\SharedNote;
use Korin\Gedcom\Model\Source;
use Korin\Gedcom\Model\Submitter;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\IndexedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordViewsTest extends TestCase
{
    private const FILE_551 = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SUBM @U1@\n"
        . "0 @U1@ SUBM\n1 NAME Олена\n1 EMAIL o@example.com\n"
        . "0 @S1@ SOUR\n1 TITL Метрична книга\n2 CONC  церкви\n1 AUTH Консисторія\n1 REPO @R1@\n2 CALN 127-1-1\n1 REPO @R9@\n"
        . "0 @R1@ REPO\n1 NAME Архів\n1 ADDR Полтава\n2 CONT Україна\n"
        . "0 @M1@ OBJE\n1 FILE photo.jpg\n2 FORM jpg\n2 TITL Портрет\n1 FILE scan.png\n2 FORM png\n"
        . "0 @N1@ NOTE Перший рядок\n1 CONT другий\n"
        . "0 TRLR\n";

    private const FILE_70 = "0 HEAD\n1 GEDC\n2 VERS 7.0\n"
        . "0 @N1@ SNOTE <p>Нотатка</p>\n1 MIME text/html\n"
        . "0 @M1@ OBJE\n1 FILE https://example.com/a.jpg\n2 FORM image/jpeg\n"
        . "0 TRLR\n";

    /** @return iterable<string, array{\Closure(string): (Document|IndexedDocument)}> */
    public static function readers(): iterable
    {
        yield 'read()' => [static fn(string $bytes): Document => Gedcom::parse($bytes)];
        yield 'open()' => [static function (string $bytes): IndexedDocument {
            $handle = fopen('php://memory', 'w+b');
            self::assertNotFalse($handle);
            fwrite($handle, $bytes);
            rewind($handle);

            return Gedcom::open($handle);
        }];
    }

    /** @param \Closure(string): (Document|IndexedDocument) $read */
    #[Test]
    #[DataProvider('readers')]
    public function recordsOf551AreRead(\Closure $read): void
    {
        $doc = $read(self::FILE_551);
        $source = $doc->source('@S1@');
        $repository = $doc->repository('@R1@');
        self::assertNotNull($source);
        self::assertNotNull($repository);

        self::assertSame('Метрична книга церкви', $source->title());
        self::assertSame('Консисторія', $source->author());
        self::assertSame(['@R1@', '@R9@'], $source->repositoryXrefs());
        self::assertSame(['Архів'], array_map(static fn(Repository $r): ?string => $r->name(), $source->repositories()));
        self::assertSame('127-1-1', $source->callNumber($repository));
        self::assertSame("Полтава\nУкраїна", $repository->address());
        $submitter = $doc->submitter('@U1@');
        self::assertNotNull($submitter);
        self::assertSame(['o@example.com'], $submitter->emails());
        self::assertSame('Олена', $submitter->name());
        self::assertSame("Перший рядок\nдругий", $doc->sharedNote('@N1@')?->content());
        self::assertNull($doc->sharedNote('@S1@'));

        $media = $doc->mediaObject('@M1@');
        self::assertNotNull($media);
        self::assertSame('Портрет', $media->title());
        self::assertSame([['photo.jpg', 'jpg', 'Портрет'], ['scan.png', 'png', null]], array_map(static fn(MediaFile $f): array => [$f->path(), $f->format(), $f->title()], $media->files()));

        $count = static fn(iterable $views): int => count(is_array($views) ? $views : iterator_to_array($views, false));
        self::assertSame([1, 1, 1, 1, 1], [$count($doc->sources()), $count($doc->repositories()), $count($doc->mediaObjects()), $count($doc->sharedNotes()), $count($doc->submitters())]);
    }

    /** @param \Closure(string): (Document|IndexedDocument) $read */
    #[Test]
    #[DataProvider('readers')]
    public function recordsOf70AreRead(\Closure $read): void
    {
        $doc = $read(self::FILE_70);

        $note = $doc->sharedNote('@N1@');
        self::assertNotNull($note);
        self::assertSame('<p>Нотатка</p>', $note->content());
        self::assertSame('text/html', $note->mediaType());
        self::assertSame('image/jpeg', ($doc->mediaObject('@M1@')?->files()[0] ?? null)?->format());
    }

    #[Test]
    public function recordsAreEdited(): void
    {
        $doc = Gedcom::parse(self::FILE_551);
        $source = $doc->source('@S1@');
        $repository = $doc->repository('@R1@');
        self::assertNotNull($source);
        self::assertNotNull($repository);

        $source->setTitle('Сповідний розпис')->setAuthor(null)->setPublication('1850')->addRepository($repository);
        $repository->setName('Державний архів');
        $doc->sharedNote('@N1@')?->setContent('Одна');
        $doc->mediaObject('@M1@')?->addFile('c.gif', 'gif', 'Схема');

        self::assertStringContainsString("0 @S1@ SOUR\n1 TITL Сповідний розпис\n1 REPO @R1@\n2 CALN 127-1-1\n1 REPO @R9@\n1 PUBL 1850\n0 @R1@ REPO\n1 NAME Державний архів\n", Gedcom::serialize($doc));
        self::assertStringContainsString("2 FORM png\n1 FILE c.gif\n2 FORM gif\n2 TITL Схема\n0 @N1@ NOTE Одна\n0 TRLR\n", Gedcom::serialize($doc));
    }

    #[Test]
    public function streamedRecordsHaveTheirView(): void
    {
        $classes = [];
        foreach (Gedcom::stream($this->handle(self::FILE_551)) as $record) {
            $view = $record->view();
            $classes[] = $view === null ? null : $view::class;
        }

        self::assertSame([null, Submitter::class, Source::class, Repository::class, MediaObject::class, SharedNote::class, null], $classes);
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
}

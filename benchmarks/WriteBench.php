<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\Document;
use PhpBench\Attributes as Bench;

/** Preserve-mode writing of a 10 MB document, unchanged and after edits. */
#[Bench\BeforeClassMethods('prepare')]
#[Bench\BeforeMethods('read')]
#[Bench\Revs(1)]
#[Bench\Iterations(5)]
final class WriteBench
{
    private Document $document;

    public static function prepare(): void
    {
        BenchFile::path(10);
    }

    public function read(): void
    {
        $this->document = Gedcom::read(BenchFile::path(10));
    }

    public function benchSerializeUnchanged(): void
    {
        Gedcom::serialize($this->document);
    }

    /** Renames every tenth surname, then writes. */
    public function benchEditAndSerialize(): void
    {
        foreach ($this->document->records() as $i => $record) {
            if ($i % 10 === 0 && $record->tag() === 'INDI') {
                $record->first('NAME')?->first('SURN')?->setValue('Ковальова');
            }
        }
        Gedcom::serialize($this->document);
    }
}

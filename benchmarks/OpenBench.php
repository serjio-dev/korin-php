<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\IndexedDocument;
use PhpBench\Attributes as Bench;

/** Indexed reading (spec section 9: index of 50 MB ≤ 64 MB in ≤ 5 s, an uncached record ≤ 1 ms). */
#[Bench\BeforeClassMethods('prepare')]
#[Bench\Revs(1)]
final class OpenBench
{
    private IndexedDocument $document;

    public static function prepare(): void
    {
        BenchFile::path(10);
        BenchFile::path(50);
    }

    #[Bench\Iterations(5)]
    public function benchOpen10MbUtf8(): void
    {
        Gedcom::open(BenchFile::path(10));
    }

    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchOpen50MbUtf8(): void
    {
        Gedcom::open(BenchFile::path(50));
    }

    public function open(): void
    {
        $this->document = Gedcom::open(BenchFile::path(10));
    }

    /** 1000 records in a fixed random order, none of them cached. */
    #[Bench\BeforeMethods('open')]
    #[Bench\Iterations(5)]
    public function benchRead1000UncachedRecords(): void
    {
        mt_srand(1);
        $last = count($this->document) - 1;
        for ($i = 0; $i < 1000; ++$i) {
            $this->document->recordAt(mt_rand(0, $last), cache: false);
        }
    }
}

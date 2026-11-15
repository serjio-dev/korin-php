<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use PhpBench\Attributes as Bench;

/** Full read into a document (spec section 9: 50 MB in ≤ 20 s and ≤ 512 MB). */
#[Bench\BeforeClassMethods('prepare')]
#[Bench\Revs(1)]
final class ReadBench
{
    public static function prepare(): void
    {
        BenchFile::path(10);
        BenchFile::path(10, 'Windows-1251');
        BenchFile::path(50);
    }

    #[Bench\Iterations(5)]
    public function benchRead10MbUtf8(): void
    {
        Gedcom::read(BenchFile::path(10));
    }

    #[Bench\Iterations(5)]
    public function benchRead10MbWindows1251(): void
    {
        Gedcom::read(BenchFile::path(10, 'Windows-1251'));
    }

    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchRead50MbUtf8(): void
    {
        Gedcom::read(BenchFile::path(50));
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use PhpBench\Attributes as Bench;

/** Streaming read, one record at a time (spec section 9: memory does not grow with the file). */
#[Bench\BeforeClassMethods('prepare')]
#[Bench\Revs(1)]
final class StreamBench
{
    public static function prepare(): void
    {
        BenchFile::path(10);
        BenchFile::path(50);
    }

    #[Bench\Iterations(5)]
    public function benchStream10MbUtf8(): void
    {
        foreach (Gedcom::stream(BenchFile::path(10)) as $record) {
            $record->node();
        }
    }

    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchStream50MbUtf8(): void
    {
        foreach (Gedcom::stream(BenchFile::path(50)) as $record) {
            $record->node();
        }
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;
use PhpBench\Attributes as Bench;

/** 5.5.1 → 7.0 of a document already read, to the output bytes (spec section 9: 50 MB in ≤ 2× the full read time). */
#[Bench\BeforeClassMethods('prepare')]
#[Bench\Revs(1)]
final class ConvertBench
{
    private Document $document;

    public static function prepare(): void
    {
        BenchFile::path(10);
        BenchFile::path(50);
    }

    public function read10Mb(): void
    {
        $this->document = Gedcom::read(BenchFile::path(10));
    }

    public function read50Mb(): void
    {
        $this->document = Gedcom::read(BenchFile::path(50));
    }

    #[Bench\BeforeMethods('read10Mb')]
    #[Bench\Iterations(5)]
    public function benchConvert10MbTo70(): void
    {
        Gedcom::convert($this->document, Version::V7_0);
    }

    #[Bench\BeforeMethods('read50Mb')]
    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchConvert50MbTo70(): void
    {
        Gedcom::convert($this->document, Version::V7_0);
    }
}

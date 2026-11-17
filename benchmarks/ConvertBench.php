<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;
use PhpBench\Attributes as Bench;

/**
 * Conversion of a 5.5.1 document already read, to the output bytes: to 7.0 (spec section 9:
 * 50 MB in ≤ 2× the full read time), and, with no target but no regression, to 5.5 and 5.5.5.
 */
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

    #[Bench\BeforeMethods('read10Mb')]
    #[Bench\Iterations(5)]
    public function benchConvert10MbTo55(): void
    {
        Gedcom::convert($this->document, Version::V5_5);
    }

    #[Bench\BeforeMethods('read10Mb')]
    #[Bench\Iterations(5)]
    public function benchConvert10MbTo555(): void
    {
        Gedcom::convert($this->document, Version::V5_5_5);
    }

    #[Bench\BeforeMethods('read50Mb')]
    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchConvert50MbTo70(): void
    {
        Gedcom::convert($this->document, Version::V7_0);
    }

    /** Streaming conversion (spec section 9: 50 MB to 7.0 in ≤ 64 MB). */
    #[Bench\Iterations(5)]
    public function benchConvertFile10MbTo70(): void
    {
        Gedcom::convertFile(BenchFile::path(10), $this->sink(), Version::V7_0);
    }

    #[Bench\Iterations(5)]
    public function benchConvertFile10MbTo555(): void
    {
        Gedcom::convertFile(BenchFile::path(10), $this->sink(), Version::V5_5_5);
    }

    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchConvertFile50MbTo70(): void
    {
        Gedcom::convertFile(BenchFile::path(50), $this->sink(), Version::V7_0);
    }

    /** @return resource the output, thrown away (on disk beyond 2 MB) */
    private function sink(): mixed
    {
        $sink = fopen('php://temp', 'w+b');
        if ($sink === false) {
            throw new \RuntimeException('Cannot open php://temp');
        }

        return $sink;
    }
}

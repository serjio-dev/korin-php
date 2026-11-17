<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\ValidationOptions;
use PhpBench\Attributes as Bench;

/** Validation of a document already read, and of a file opened with an index (record by record). */
#[Bench\BeforeClassMethods('prepare')]
#[Bench\Revs(1)]
final class ValidateBench
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

    #[Bench\BeforeMethods('read10Mb')]
    #[Bench\Iterations(5)]
    public function benchValidate10Mb(): void
    {
        Gedcom::validate($this->document, (new ValidationOptions())->withPlausibility());
    }

    /** Index and validation: the whole file in the memory of its index. */
    #[Bench\Iterations(5)]
    public function benchValidateOpen10Mb(): void
    {
        Gedcom::validate(Gedcom::open(BenchFile::path(10)), (new ValidationOptions())->withPlausibility());
    }

    #[Bench\Iterations(2)]
    #[Bench\Groups(['large'])]
    public function benchValidateOpen50Mb(): void
    {
        Gedcom::validate(Gedcom::open(BenchFile::path(50)), (new ValidationOptions())->withPlausibility());
    }
}

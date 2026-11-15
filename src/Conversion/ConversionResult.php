<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Syntax\Document;

/** The converted document and what could not be carried over. */
final readonly class ConversionResult
{
    public function __construct(
        private Document $document,
        private LossReport $losses,
    ) {}

    /** A new document of the target version; written in preserve mode it gives the conversion output. */
    public function document(): Document
    {
        return $this->document;
    }

    public function losses(): LossReport
    {
        return $this->losses;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Parser;

/**
 * The conversion output and what could not be carried over. The converted document is read
 * from the output only when it is asked for: writing a conversion needs only output(). The
 * output is fixed when the conversion ends; editing document() does not change it.
 */
final class ConversionResult
{
    /** @internal created by the Converter; $document, when given, is the output already read */
    public function __construct(
        private readonly LossReport $losses,
        private readonly string $output,
        private readonly ReadOptions $read = new ReadOptions(),
        private ?Document $document = null,
    ) {}

    /** A new document of the target version, read from output() on first use. */
    public function document(): Document
    {
        return $this->document ??= (new Parser())->parse($this->output, $this->read);
    }

    /** The bytes of the converted file, ready to be written. */
    public function output(): string
    {
        return $this->output;
    }

    public function losses(): LossReport
    {
        return $this->losses;
    }
}

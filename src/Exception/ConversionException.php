<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

use Korin\Gedcom\Conversion\Loss;

/** A conversion between versions that is not supported, or a loss under LossPolicy::Fail. */
final class ConversionException extends \RuntimeException implements GedcomException
{
    public function __construct(string $message, public readonly ?Loss $loss = null)
    {
        parent::__construct($message);
    }
}

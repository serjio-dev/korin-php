<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A change to a document that is read only: a record of {@see \Korin\Gedcom\Gedcom::open()}; edit a copy. */
final class ReadOnlyException extends \LogicException implements GedcomException {}

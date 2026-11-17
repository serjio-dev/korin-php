<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A version the library reads but does not write (GEDCOM 4.0): convert the document to 5.5 or later. */
final class UnsupportedVersionException extends \LogicException implements GedcomException {}

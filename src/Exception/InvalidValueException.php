<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A value that does not follow the grammar of a version, or one the version cannot write. */
final class InvalidValueException extends \InvalidArgumentException implements GedcomException {}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A node path does not follow the path grammar. */
final class InvalidPathException extends \InvalidArgumentException implements GedcomException {}

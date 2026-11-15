<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A file or stream could not be read or written. */
final class IoException extends \RuntimeException implements GedcomException {}

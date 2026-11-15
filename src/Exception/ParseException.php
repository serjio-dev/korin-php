<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** Malformed input in strict mode, or input that cannot be read at all. */
final class ParseException extends \RuntimeException implements GedcomException {}

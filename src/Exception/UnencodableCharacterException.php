<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A character has no representation in the target encoding. */
final class UnencodableCharacterException extends \RuntimeException implements GedcomException {}

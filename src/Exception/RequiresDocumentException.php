<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A view of a streamed record asked for another record: read the file with read() or open(). */
final class RequiresDocumentException extends \LogicException implements GedcomException {}

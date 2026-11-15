<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** The node was removed from its document. */
final class DetachedNodeException extends \LogicException implements GedcomException {}

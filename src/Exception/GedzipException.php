<?php

declare(strict_types=1);

namespace Korin\Gedcom\Exception;

/** A GEDZIP archive that cannot be read or written: not a ZIP, no gedcom.ged, a limit exceeded, no ext-zip. */
final class GedzipException extends \RuntimeException implements GedcomException {}

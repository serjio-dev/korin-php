<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;

/** @internal one group of checks of the validator */
interface Rule
{
    /** @return iterable<Diagnostic> */
    public function check(Document $document, Profile $profile): iterable;
}

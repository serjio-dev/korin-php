<?php

declare(strict_types=1);

namespace Korin\Gedcom\Writer;

enum WriteMode
{
    /** Unchanged lines byte for byte (in their original encoding), changed and new lines by the version's rules. */
    case Preserve;

    /** Every line written anew by the version's rules, with one line terminator. */
    case Normalize;
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

/** How the data of a loss report entry was kept in the output. */
enum StoredAs: string
{
    /** As an extension structure ("_" tag). */
    case Extension = 'extension';

    /** As the text of a NOTE. */
    case Note = 'note';

    /** Not at all. */
    case Dropped = 'dropped';
}

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

    /** In another standard structure of the target version (5.5: the value of EVEN as its TYPE). */
    case Moved = 'moved';

    /** Not at all. */
    case Dropped = 'dropped';
}

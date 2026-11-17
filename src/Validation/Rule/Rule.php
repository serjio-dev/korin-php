<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Records\Records;

/**
 * @internal one group of checks of the validator. It sees the records one at a time, in order,
 * so that a file is read once for all rules; what it needs of other records it looks up.
 */
interface Rule
{
    public function begin(Records $records, Profile $profile): void;

    /** One record: its document (the whole one, or the record's own) and node, and its key in $records. */
    public function record(Document $document, int $id, int $key): void;

    /** @return list<Diagnostic> in the order found */
    public function end(): array;
}

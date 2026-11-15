<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Syntax\Document;

/**
 * One conversion rule. It changes one record at a time, so that a conversion can stream: what
 * it must know about the whole input comes from the Prepass, which runs only when a rule needs it.
 *
 * @internal
 */
interface Rule
{
    /** Whether the rule needs Context::prepass(): facts about the whole input before the first record. */
    public function needsPrepass(): bool;

    /** Converts one record (HEAD and TRLR included) of the document in place. */
    public function record(Document $document, int $record, Context $context): void;
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * The 7.0 shared note SNOTE is the 5.5.1 NOTE record, and the SNOTE that points to it is a
 * NOTE pointer. Extensions are left alone.
 *
 * @internal
 */
final class NoteRecords implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['SNOTE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        // A pointer to @VOID@ may have become _SNOTE already (Voids).
        if ($document->tagOf($id) === 'SNOTE' && !$context->inExtension($document->parentOf($id))) {
            $document->setTag($id, 'NOTE');
        }

        return true;
    }
}

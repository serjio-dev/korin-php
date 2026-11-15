<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * The 5.5.1 NOTE record is the 7.0 shared note SNOTE, and a NOTE that points to it (in 5.5.1 a
 * NOTE pointer always points to a NOTE record) is SNOTE too. NOTE with text stays NOTE.
 *
 * @internal
 */
final class NoteRecords implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($document->tagOf($id) === 'NOTE' && ($document->parentOf($id) === 0 || Escaping::isPointer((string) $document->payloadOf($id)))) {
            $document->setTag($id, 'SNOTE');
        }

        return true;
    }
}

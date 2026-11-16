<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV55;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5 ASSO names the type of the record it points to, which in 5.5.1 is always an individual:
 * TYPE INDI, its first substructure.
 *
 * @internal
 */
final class Associations implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['ASSO'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($document->tagOf($document->parentOf($id)) !== 'INDI' || $context->inExtension($id)) {
            return true;
        }
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'TYPE') {
                return true;
            }
        }
        $document->createNode($id, null, 'TYPE', 'INDI', $document->childIds($id)[0] ?? null);

        return true;
    }
}

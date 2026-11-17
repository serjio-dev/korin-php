<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * 7.0 EVEN of an individual or a family requires TYPE, 5.5.1 does not: an EVEN with a value
 * ("Moved to Osaka", which describes the event) and no TYPE gets the value as its first TYPE.
 * The conversion back removes a TYPE equal to the value.
 *
 * @internal
 */
final class Events implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['EVEN'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent === 0 || $document->parentOf($parent) !== 0 || !in_array($document->tagOf($parent), ['INDI', 'FAM'], true) || $context->inExtension($id)) {
            return true;
        }
        $value = $context->value($id);
        if ($value === null || $value === '' || str_contains($value, "\n") || $document->node($id)->first('TYPE') !== null) {
            return true;
        }
        $context->setValue($document->createNode($id, null, 'TYPE', null, $this->first($document, $id)), $value);

        return true;
    }

    private function first(Document $document, int $id): ?int
    {
        foreach ($document->hasChildren($id) ? $document->childIds($id) : [] as $child) {
            if ($document->tagOf($child) !== 'CONC' && $document->tagOf($child) !== 'CONT') {
                return $child;
            }
        }

        return null;
    }
}

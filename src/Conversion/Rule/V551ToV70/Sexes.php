<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * SEX U with _SEX X, which the conversion to 5.5.1 writes for SEX X, is SEX X.
 *
 * @internal
 */
final class Sexes implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['_SEX'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent === 0 || $document->tagOf($parent) !== 'SEX' || $document->payloadOf($parent) !== 'U' || $document->payloadOf($id) !== 'X' || $document->hasChildren($id)) {
            return true;
        }
        $document->removeNode($id);
        $context->setValue($parent, 'X');

        return false;
    }
}

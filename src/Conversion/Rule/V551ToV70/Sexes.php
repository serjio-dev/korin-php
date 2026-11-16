<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * SEX U with _SEX X, which the conversion to 5.5.1 writes for SEX X, is SEX X (to 5.5.5 also
 * _SEX N, SEX N).
 *
 * @internal
 */
final readonly class Sexes implements NodeRule
{
    /** @param list<string> $values the target's values 5.5.1 does not have */
    public function __construct(private array $values = ['X']) {}

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
        if ($parent === 0 || $document->tagOf($parent) !== 'SEX' || $document->payloadOf($parent) !== 'U' || !in_array($value = $document->payloadOf($id), $this->values, true) || $document->hasChildren($id)) {
            return true;
        }
        $document->removeNode($id);
        $context->setValue($parent, $value);

        return false;
    }
}

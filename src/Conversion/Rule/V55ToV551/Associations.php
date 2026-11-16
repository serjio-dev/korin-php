<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V55ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * ASSO names the type of the record it points to in 5.5 (TYPE); 5.5.1 associates individuals
 * only. TYPE INDI goes; an association with another record becomes an extension (KL2001).
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
            if ($document->tagOf($child) !== 'TYPE') {
                continue;
            }
            if (strcasecmp((string) $document->payloadOf($child), 'INDI') !== 0) {
                return $context->unsupported($id, 'KL2001', '5.5.1 associates individuals only');
            }
            $document->removeNode($child);
        }

        return true;
    }
}

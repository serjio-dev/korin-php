<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * The vendor tag _UID is the 7.0 UID. Its value is kept as it is: the UID term says an existing
 * identifier "should be used without modification", so a PAF-style UUID with a checksum stays.
 * Substructures of extensions are left alone.
 *
 * @internal
 */
final class Uids implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['_UID'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent !== 0 && !str_starts_with($document->tagOf($parent), '_')) {
            $document->setTag($id, 'UID');
        }

        return true;
    }
}

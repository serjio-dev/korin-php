<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * The 7.0 UID is the vendor tag _UID, which 5.5.1 programs write; its value is kept.
 * Extensions are left alone.
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
        return ['UID'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (!$context->inExtension($document->parentOf($id))) {
            $document->setTag($id, '_UID');
        }

        return true;
    }
}

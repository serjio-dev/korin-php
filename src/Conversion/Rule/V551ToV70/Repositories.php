<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5.1 REPO of a source without a pointer (a repository known only by its call numbers) is
 * REPO @VOID@ in 7.0, which requires the pointer.
 *
 * @internal
 */
final class Repositories implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['REPO'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent !== 0 && $document->tagOf($parent) === 'SOUR' && $document->parentOf($parent) === 0
            && ($document->payloadOf($id) ?? '') === '' && !$context->inExtension($id)) {
            $document->setPayload($id, '@VOID@');
        }

        return true;
    }
}

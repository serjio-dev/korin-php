<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * A pointer to @VOID@, the 7.0 null pointer ("FAMC @VOID@" with a PHRASE saying who the
 * parents were), points to no record in 5.5.1, where it would be a dangling pointer: the
 * structure becomes an extension with its 7.0 tag and its substructures (_FAMC @VOID@), or is
 * dropped under LossPolicy::DropWithReport (KL4001). Extensions are left alone. REPO @VOID@ of
 * a source, a repository with only its call numbers, is 5.5.1 REPO without a pointer.
 *
 * @internal
 */
final class Voids implements NodeRule
{
    private const VOID = '@VOID@';

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): ?array
    {
        return null;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (!$document->mayContain($id, '@') || $document->payloadOf($id) !== self::VOID || $context->inExtension($id)) {
            return true;
        }

        $parent = $document->parentOf($id);
        if ($document->tagOf($id) === 'REPO' && $parent !== 0 && $document->tagOf($parent) === 'SOUR' && $document->parentOf($parent) === 0) {
            $document->setPayload($id, null);

            return true;
        }

        return $context->unsupported($id, 'KL4001', 'A pointer to @VOID@ has no record to point to in 5.5.1');
    }
}

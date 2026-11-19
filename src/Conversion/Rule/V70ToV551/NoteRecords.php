<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * The 7.0 shared note SNOTE is the 5.5.1 NOTE record, and the SNOTE that points to it is a
 * NOTE pointer, where 5.5.1 has a NOTE that can be one: an SNOTE under SOUR.DATA.EVEN.PLAC, or
 * of HEAD (whose NOTE is text only), stays for the structures rule, which keeps it as _SNOTE.
 * Extensions are left alone.
 *
 * @internal
 */
final class NoteRecords implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['SNOTE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        // A pointer to @VOID@ may have become _SNOTE already (Voids).
        $parent = $document->parentOf($id);
        if ($document->tagOf($id) !== 'SNOTE' || $context->inExtension($parent)) {
            return true;
        }
        $path = ['NOTE'];
        for ($above = $parent; $above !== 0; $above = $document->parentOf($above)) {
            array_unshift($path, $document->tagOf($above));
        }
        if ($parent === 0 || Profile::for(Version::V5_5_1)->resolve($path)?->pointerTarget() !== null) {
            $document->setTag($id, 'NOTE');
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V555ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * HEAD.GEDC.FORM.VERS, the version 5.5.5 requires under FORM, is not 5.5.1: it is removed, like
 * GEDC.VERS it is set by the conversion. A 5.5.5 file has one SUBM record; the others, which
 * the conversion to 5.5.5 keeps as _SUBM records, are SUBM records again.
 *
 * @internal
 */
final class FileHeader implements Rule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        $tag = $document->tagOf($record);
        if ($tag === '_SUBM' && $document->xrefOf($record) !== null) {
            $document->setTag($record, 'SUBM');

            return;
        }
        if ($tag !== 'HEAD') {
            return;
        }
        $version = $document->node($record)->first('GEDC')?->first('FORM')?->first('VERS');
        if ($version !== null && !$document->hasChildren($version->id())) {
            $version->remove();
        }
    }
}

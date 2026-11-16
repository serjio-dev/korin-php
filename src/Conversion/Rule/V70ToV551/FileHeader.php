<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5.1 requires HEAD.GEDC.FORM, which 7.0 dropped (a 7.0 file is always lineage-linked):
 * GEDC.FORM LINEAGE-LINKED is added after GEDC.VERS. HEAD.CHAR and GEDC.VERS are set by the
 * converter.
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
        if ($document->tagOf($record) !== 'HEAD') {
            return;
        }
        $head = $document->node($record);
        $gedc = $head->first('GEDC') ?? $head->addChild('GEDC');
        if ($gedc->first('FORM') !== null) {
            return;
        }
        $vers = ($gedc->first('VERS') ?? $gedc->addChild('VERS', '5.5.1'))->id();
        $children = $document->childIds($gedc->id());
        $document->createNode($gedc->id(), null, 'FORM', 'LINEAGE-LINKED', $children[(int) array_search($vers, $children, true) + 1] ?? null);
    }
}

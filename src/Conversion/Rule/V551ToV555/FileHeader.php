<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV555;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5.5 requires HEAD.GEDC.FORM LINEAGE-LINKED with a VERS of its own, 5.5.5: FORM is added
 * after GEDC.VERS when missing, and its VERS is set. HEAD.CHAR and GEDC.VERS are set by the
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
        $form = $gedc->first('FORM');
        if ($form === null) {
            $vers = ($gedc->first('VERS') ?? $gedc->addChild('VERS', '5.5.5'))->id();
            $children = $document->childIds($gedc->id());
            $form = $document->node($document->createNode($gedc->id(), null, 'FORM', 'LINEAGE-LINKED', $children[(int) array_search($vers, $children, true) + 1] ?? null));
        }
        $vers = $form->first('VERS');
        if ($vers === null) {
            $form->addChild('VERS', '5.5.5');
        } elseif ($vers->value() !== '5.5.5') {
            $vers->setValue('5.5.5');
        }
    }
}

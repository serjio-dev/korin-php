<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * HEAD.CHAR and HEAD.GEDC.FORM describe the file, not its data, and 7.0 has neither (a 7.0
 * file is always UTF-8 and lineage-linked): they are removed without a loss (SPEC section 7,
 * "Source housekeeping structures"). GEDC.VERS is set by the converter.
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
        foreach ($document->childIds($record) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'CHAR') {
                $document->removeNode($child);
            } elseif ($tag === 'GEDC') {
                foreach ($document->childIds($child) as $form) {
                    if ($document->tagOf($form) === 'FORM') {
                        $document->removeNode($form);
                    }
                }
            }
        }
    }
}

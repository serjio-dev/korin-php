<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * 7.0 has no SUBN (the LDS submission record). Its substructures move to HEAD._SUBN, one per
 * SUBN record, so a conversion back can restore it; the record and HEAD.SUBN, the pointer to
 * it, are removed. Done when HEAD is converted, with the SUBN records from the prepass: a SUBN
 * record may be anywhere, HEAD is first.
 *
 * @internal
 */
final class Submissions implements Rule
{
    public function needsPrepass(): bool
    {
        return true;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        if ($document->tagOf($record) !== 'HEAD') {
            return;
        }
        foreach ($document->childIds($record) as $child) {
            if ($document->tagOf($child) === 'SUBN') {
                $document->removeNode($child);
            }
        }
        foreach ($context->prepass()->submissions as $submission) {
            if (!$document->contains($submission)) {
                continue;
            }
            $target = $document->createNode($record, null, '_SUBN', null);
            foreach ($document->childIds($submission) as $child) {
                $document->moveNode($child, $target);
            }
            $document->removeNode($submission);
        }
    }
}

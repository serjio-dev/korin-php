<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * HEAD._SUBN, where the conversion to 7.0 keeps a 5.5.1 SUBN record, becomes a SUBN record
 * again (before TRLR, xref @X<n>@) with its substructures, and HEAD.SUBN points to the first
 * one in its place. Needs the prepass: the new xref must not be one the input has.
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
        $pointed = false;
        foreach ($document->childIds($record) as $child) {
            if ($document->tagOf($child) !== '_SUBN') {
                continue;
            }
            $xref = $context->newXref();
            $submission = $context->extractRecord('SUBN', $xref);
            foreach ($document->childIds($child) as $substructure) {
                $document->moveNode($substructure, $submission);
            }
            if (!$pointed) {
                $document->createNode($record, null, 'SUBN', $xref, $child);
                $pointed = true;
            }
            $document->removeNode($child);
        }
    }
}

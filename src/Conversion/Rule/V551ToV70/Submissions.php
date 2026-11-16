<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * 7.0 has no SUBN (the LDS submission record). Its substructures move to HEAD._SUBN, one per
 * SUBN record, so a conversion back can restore it; the record and HEAD.SUBN, the pointer to
 * it, are removed. Done when HEAD is converted, with the SUBN records from the prepass: a SUBN
 * record may be anywhere, HEAD is first. A SUBN record an earlier step of a route made
 * (7.0 → 5.5.1 → 5.5.5) moves when it is converted, its values written anew.
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
        $tag = $document->tagOf($record);
        if ($tag === 'SUBN') {
            $head = $document->head();
            if ($head !== null) {
                $this->move($document, $record, $head->id(), $context);
            }

            return;
        }
        if ($tag !== 'HEAD') {
            return;
        }
        foreach ($document->childIds($record) as $child) {
            if ($document->tagOf($child) === 'SUBN') {
                $document->removeNode($child);
            }
        }
        foreach ($context->prepass()->submissions as $submission) {
            if ($document->contains($submission)) {
                $this->move($document, $submission, $record, null);
            }
        }
    }

    /** @param ?Context $context to write the values anew: HEAD, where they go, is converted already */
    private function move(Document $document, int $submission, int $head, ?Context $context): void
    {
        $target = $document->createNode($head, null, '_SUBN', null);
        foreach ($document->childIds($submission) as $child) {
            $document->moveNode($child, $target);
        }
        $document->removeNode($submission);
        $stack = $context === null ? [] : $document->childIds($target);
        while ($stack !== []) {
            $id = array_pop($stack);
            $payload = $document->payloadOf($id);
            $value = $payload !== null && Escaping::isPointer($payload) ? null : $document->node($id)->valueFor($document->version());
            if ($value !== null) {
                $context?->setValue($id, $value);
            }
            foreach ($document->childIds($id) as $child) {
                if ($document->tagOf($child) !== 'CONC' && $document->tagOf($child) !== 'CONT') {
                    $stack[] = $child;
                }
            }
        }
    }
}

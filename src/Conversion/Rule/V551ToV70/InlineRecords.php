<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * 7.0 has no inline media or source: OBJE and SOUR substructures point to records. Each one
 * without a pointer becomes a new record @X<n>@ (before TRLR, in order of appearance), and
 * the substructure points to it:
 *
 * - OBJE: its substructures move to the record, except TITL, which the 7.0 link keeps;
 * - SOUR: the description (its value) becomes the record's NOTE, as in the gedcom7code
 *   converter; TEXT moves under a new DATA of the citation, the rest stays on the citation.
 *
 * HEAD.SOUR names a program, not a source, and what is inside an extension is left alone.
 *
 * @internal
 */
final class InlineRecords implements NodeRule
{
    public function needsPrepass(): bool
    {
        return true;
    }

    public function tags(): array
    {
        return ['OBJE', 'SOUR'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent === 0) {
            return true;
        }
        // Inside an extension at any depth (_NOTE.SOUR.OBJE of a 5.5 note), not only directly under one.
        if ($document->tagOf($parent) === 'HEAD' || $context->inExtension($parent)) {
            return true;
        }
        $payload = $document->payloadOf($id);
        if ($payload !== null && Escaping::isPointer($payload)) {
            return true;
        }
        $node = $document->node($id);
        $value = $node->value();
        $xref = $context->newXref();
        $record = $context->extractRecord($document->tagOf($id), $xref);
        if ($document->tagOf($id) === 'OBJE') {
            foreach ($node->children() as $child) {
                if ($child->tag() !== 'TITL') {
                    $document->moveNode($child->id(), $record);
                }
            }
        } else {
            $data = null;
            foreach ($node->children() as $child) {
                if ($child->tag() === 'TEXT') {
                    $data ??= $document->createNode($id, null, 'DATA', null, $child->id());
                    $document->moveNode($child->id(), $data);
                }
            }
        }
        // Written by the 7.0 rules, like every structure a rule creates; the moved ones are converted with the new record.
        if ($value !== null && $value !== '') {
            $context->setValue($document->createNode($record, null, 'NOTE', null), $value);
        }
        $node->setPointer($xref);

        return true;
    }
}

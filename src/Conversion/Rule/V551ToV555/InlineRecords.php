<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV555;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * 5.5.5 has no inline media or source: OBJE and SOUR substructures point to records. Each one
 * without a pointer becomes a new record @X<n>@ (before TRLR, in order of appearance), and the
 * substructure points to it:
 *
 * - OBJE: its substructures move to the record; the media record of 5.5.5 has the title under
 *   FILE, so TITL moves under the first FILE, and FORM.MEDI is FORM.TYPE there. A FORM beside
 *   FILE (the other 5.5.1 form of the link) moves into the first FILE;
 * - SOUR: the description (its value) becomes the record's TITL; TEXT moves under a new DATA
 *   of the citation, the rest stays on the citation.
 *
 * HEAD.SOUR names a program, not a source, and substructures of extensions are left alone.
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
        $above = $document->tagOf($parent);
        if ($above === 'HEAD' || str_starts_with($above, '_')) {
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
            $this->media($document, $id, $record);
        } else {
            $data = null;
            foreach ($node->children() as $child) {
                if ($child->tag() === 'TEXT') {
                    $data ??= $document->createNode($id, null, 'DATA', null, $child->id());
                    $document->moveNode($child->id(), $data);
                }
            }
            if ($value !== null && $value !== '') {
                $context->setValue($document->createNode($record, null, 'TITL', null), $value);
            }
        }
        $node->setPointer($xref);

        return true;
    }

    private function media(Document $document, int $link, int $record): void
    {
        $file = null;
        $moved = [];
        foreach ($document->childIds($link) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'FILE') {
                $file ??= $child;
            }
            if ($tag !== 'TITL' && $tag !== 'FORM') {
                $document->moveNode($child, $record);
            } else {
                $moved[] = $child;
            }
        }
        foreach ($moved as $child) {
            if ($file === null) {
                $document->moveNode($child, $record);
            } elseif ($document->tagOf($child) === 'FORM') {
                $document->moveNode($child, $file, $document->childIds($file)[0] ?? null);
            } else {
                $document->moveNode($child, $file);
            }
        }
        foreach ($file === null ? [] : $document->childIds($file) as $form) {
            if ($document->tagOf($form) !== 'FORM') {
                continue;
            }
            foreach ($document->childIds($form) as $medi) {
                if ($document->tagOf($medi) === 'MEDI') {
                    $document->setTag($medi, 'TYPE');
                }
            }
        }
    }
}

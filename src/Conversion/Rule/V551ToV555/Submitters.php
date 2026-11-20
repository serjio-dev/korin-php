<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV555;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * A 5.5.5 file has exactly one SUBM record, directly after HEAD, and HEAD.SUBM points to it.
 * The submitter is the record HEAD.SUBM points to, or else the first SUBM record, to which a
 * missing HEAD.SUBM then points, unless HEAD has a _SUBM (a pointer to no record, 7.0 @VOID@);
 * it moves after HEAD. Every other SUBM record becomes an
 * extension record (_SUBM, KL2002), which the conversion back restores. A file without a SUBM
 * record stays without one: there is no submitter to name.
 *
 * @internal
 */
final class Submitters implements Rule
{
    private ?int $submitter = null;

    /** The document of HEAD and the submitter: convertFile() converts the other records in documents of their own. */
    private ?Document $head = null;

    public function needsPrepass(): bool
    {
        return false;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        $tag = $document->tagOf($record);
        if ($tag === 'SUBM' && $this->submitter !== null && ($record !== $this->submitter || $document !== $this->head)) {
            $context->unsupported($record, 'KL2002', '5.5.5 allows one SUBM record');

            return;
        }
        if ($tag !== 'HEAD') {
            return;
        }
        $this->submitter = null;
        $this->head = $document;
        $pointer = $document->node($record)->first('SUBM');
        $xref = $pointer?->pointer();
        $submitter = $xref === null ? null : $document->record($xref);
        if ($submitter !== null && $submitter->tag() !== 'SUBM') {
            return;
        }
        $id = $submitter?->id();
        if ($id === null && $pointer === null) {
            foreach ($document->childIds(0) as $candidate) {
                if ($document->tagOf($candidate) === 'SUBM' && $document->xrefOf($candidate) !== null) {
                    $id = $candidate;
                    break;
                }
            }
            // _SUBM: a pointer to no record (7.0 @VOID@), which says there is no submitter to name.
            if ($id !== null && $document->node($record)->first('_SUBM') === null) {
                $document->createNode($record, null, 'SUBM', $document->xrefOf($id));
            }
        }
        if ($id === null) {
            return;
        }
        $records = $document->childIds(0);
        $next = $records[(int) array_search($record, $records, true) + 1] ?? null;
        if ($next !== $id) {
            $document->moveNode($id, 0, $next);
        }
        $this->submitter = $id;
    }
}

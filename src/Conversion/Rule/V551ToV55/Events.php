<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV55;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;

/**
 * The value of EVEN, which 5.5 does not have: 5.5 describes the event with TYPE
 * (<EVENT_DESCRIPTOR>, the element 5.5.1 writes in the value), so the value becomes its TYPE;
 * an EVEN with a TYPE already keeps it in a NOTE (KL3004 either way).
 *
 * @internal
 */
final class Events implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['EVEN'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $value = $context->value($id);
        if ($value === null || $value === '' || !str_contains((string) $context->sourceStructure($id)?->payload, '<EVENT_DESCRIPTOR>')) {
            return true;
        }
        $type = false;
        foreach ($document->childIds($id) as $child) {
            $type = $type || $document->tagOf($child) === 'TYPE';
        }
        $context->setValue($id, null);
        if ($type) {
            $context->addText($id, 'NOTE', $value);
            $context->loss('KL3004', Severity::Warning, $id, StoredAs::Note, '5.5 EVEN has no value; it has a TYPE already');
        } else {
            $first = $document->childIds($id)[0] ?? null;
            $created = $context->addText($id, 'TYPE', $value);
            if ($first !== null) {
                $document->moveNode($created, $id, $first);
            }
            $context->loss('KL3004', Severity::Warning, $id, StoredAs::Moved, '5.5 EVEN has no value: it is its TYPE');
        }

        return true;
    }
}

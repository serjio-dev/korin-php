<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;

/**
 * The TYPE the conversion to 7.0 gives an EVEN without one, its value again
 * (EVEN Moved to Osaka + TYPE Moved to Osaka), is removed: 5.5.1 does not require it. The value
 * of RESI, which 5.5.1 writes without one, is a NOTE of it (KL3005).
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
        return ['EVEN', 'RESI'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent === 0 || $document->parentOf($parent) !== 0 || !in_array($document->tagOf($parent), ['INDI', 'FAM'], true) || $context->inExtension($id)) {
            return true;
        }
        $value = $context->value($id);
        if ($document->tagOf($id) === 'RESI') {
            if ($value !== null && $value !== '') {
                $context->setValue($id, null);
                $context->addText($id, 'NOTE', $value);
                $context->loss('KL3005', Severity::Warning, $id, StoredAs::Note, '5.5.1 RESI has no value: it is a NOTE');
            }

            return true;
        }
        $type = $document->node($id)->first('TYPE');
        if ($value !== null && $value !== '' && $type !== null && !$document->hasChildren($type->id()) && $context->value($type->id()) === $value) {
            $document->removeNode($type->id());
        }

        return true;
    }
}

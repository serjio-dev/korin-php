<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V555ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5.5 writes years with at least 3 digits ("050"), 5.5.1 and 7.0 as they are ("50"): the
 * zeroes go, so a date converted to 5.5.5 and back is the date it was.
 *
 * @internal
 */
final class Dates implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['DATE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($document->parentOf($id) === 0 || $context->inExtension($id) || !str_contains((string) $context->sourceElement($id), 'DATE')) {
            return true;
        }
        $value = $document->payloadOf($id);
        if ($value !== null && str_contains($value, '0')) {
            $years = Spellings::years($value);
            if ($years !== $value) {
                $document->setPayload($id, $years);
            }
        }

        return true;
    }
}

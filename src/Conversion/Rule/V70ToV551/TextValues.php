<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * Text values by the 5.5.1 line rules: every "@" of a text is doubled ("with @ inside" →
 * "with @@ inside"), not only a leading one; "@#DJULIAN@" in a 7.0 text is text, so it is
 * "@@#DJULIAN@@". Long lines are split into CONC when the output is written. Rules after this
 * one read values by the 5.5.1 rules (Context::value()).
 *
 * @internal
 */
final class TextValues implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): ?array
    {
        return null;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($this->changes($document, $id) && $context->isFromSource($id)) {
            $context->setValue($id, $document->node($id)->value());
        }

        return true;
    }

    /** Whether the value is written differently in 5.5.1: it has an "@" that is not a pointer. */
    private function changes(Document $document, int $id): bool
    {
        if ($this->hasAt($document, $id)) {
            return true;
        }
        if (!$document->hasChildren($id)) {
            return false;
        }
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'CONT' && $this->hasAt($document, $child)) {
                return true;
            }
        }

        return false;
    }

    private function hasAt(Document $document, int $id): bool
    {
        if (!$document->mayContain($id, '@')) {
            return false;
        }
        $payload = (string) $document->payloadOf($id);

        return str_contains($payload, '@') && !Escaping::isPointer($payload);
    }
}

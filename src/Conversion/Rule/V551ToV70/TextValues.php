<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * Text values by the 7.0 line rules: CONC lines are joined (7.0 has no CONC and no line length
 * limit; CONT stays), and "@" is doubled only at the start of a line instead of everywhere
 * ("with @@ inside" → "with @ inside"). "@#…@" sequences in text are kept as text; in DATE
 * they are calendar escapes, which the date rule converts.
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
        if ($document->tagOf($id) !== 'DATE' && $this->changes($document, $id)) {
            $context->setValue($id, $document->node($id)->value());
        }

        return true;
    }

    /** Whether the value is written differently in 7.0: it has CONC lines or an "@". */
    private function changes(Document $document, int $id): bool
    {
        if ($this->hasAt($document, $id)) {
            return !Escaping::isPointer((string) $document->payloadOf($id));
        }
        if (!$document->hasChildren($id)) {
            return false;
        }
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'CONC' || ($tag === 'CONT' && $this->hasAt($document, $child))) {
                return true;
            }
        }

        return false;
    }

    private function hasAt(Document $document, int $id): bool
    {
        return $document->mayContain($id, '@') && str_contains((string) $document->payloadOf($id), '@');
    }
}

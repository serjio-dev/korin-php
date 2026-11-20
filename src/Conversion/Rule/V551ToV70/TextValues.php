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
 * ("with @@ inside" → "with @ inside"). "@#…@" sequences are kept as text ("@#DJULIAN@ x" →
 * "@@#DJULIAN@ x"); the date rule, which runs after this one, reads calendar escapes in DATE
 * from that text. Rules after this one read values by the 7.0 rules (Context::value()).
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

    /** Whether the value is written differently in 7.0: it has CONC lines or an "@". */
    private function changes(Document $document, int $id): bool
    {
        if ($document->mayContain($id, '@') && str_contains($payload = (string) $document->payloadOf($id), '@')) {
            return !Escaping::isPointer($payload);
        }
        if (!$document->hasChildren($id) || !$document->hasContinuation($id, '@')) {
            return false;
        }
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'CONC' || ($tag === 'CONT' && str_contains((string) $document->payloadOf($child), '@'))) {
                return true;
            }
        }

        return false;
    }
}

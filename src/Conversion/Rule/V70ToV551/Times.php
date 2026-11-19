<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * Times by the 5.5.1 grammar, which has no time zone: a 7.0 time in UTC ("15:43:20Z") is kept as
 * an extension, _TIME (KL3001); converting up gives it back.
 *
 * @internal
 */
final readonly class Times implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['TIME'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (($context->sourceStructure($id)->uri ?? null) !== 'https://gedcom.io/terms/v7/TIME' || !str_ends_with((string) $context->value($id), 'Z')) {
            return true;
        }

        return $context->unsupported($id, 'KL3001', 'The time has no 5.5.1 form: 5.5.1 times have no time zone');
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * A record whose extension tag HEAD.SCHMA documents as a standard record (TAG _USER
 * https://gedcom.io/terms/v7/record-SUBM) is that record in 7.0, and gets the standard tag in
 * 5.5.1, which has no SCHMA: _USER → SUBM. HEAD is converted before the records after it, so its
 * SCHMA may be _SCHMA already.
 *
 * @internal
 */
final class Aliases implements Rule
{
    private const RECORD = '~^https://gedcom\.io/terms/v7/record-([A-Z]+)$~';

    public function needsPrepass(): bool
    {
        return false;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        $tag = $document->tagOf($record);
        if (!str_starts_with($tag, '_')) {
            return;
        }
        $head = $context->head();
        $schema = $head?->first('SCHMA') ?? $head?->first('_SCHMA');
        foreach ($schema?->all('TAG') ?? [] as $definition) {
            if (preg_match('/^(_[A-Z0-9_]+) +(\S+)$/', (string) $definition->value(), $m) === 1 && $m[1] === $tag && preg_match(self::RECORD, $m[2], $standard) === 1) {
                $document->setTag($record, $standard[1]);

                return;
            }
        }
    }
}

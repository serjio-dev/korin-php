<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V555ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5.1 ADDR has a value, the address as lines; 5.5.5 has none. The value is the first
 * substructure _ADDR, where the conversion to 5.5.5 keeps a value it cannot write, or else the
 * lines ADR1–ADR3, or else CITY, STAE POST and CTRY on one line ("Kyiv, 01001, Ukraine"). An
 * address with none of them stays without a value.
 *
 * @internal
 */
final class Addresses implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['ADDR'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($document->parentOf($id) === 0 || $context->inExtension($id) || ($context->value($id) ?? '') !== '') {
            return true;
        }
        $first = $document->childIds($id)[0] ?? null;
        if ($first !== null && $document->tagOf($first) === '_ADDR' && !$this->hasSubstructures($document, $first)) {
            $context->setValue($id, $context->value($first));
            $document->removeNode($first);

            return true;
        }
        $lines = [];
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if (in_array($tag, ['ADR1', 'ADR2', 'ADR3'], true)) {
                $lines[] = (string) $context->value($child);
            }
        }
        $value = $lines !== [] ? implode("\n", $lines) : self::compose($document, $id, $context);
        if ($value !== '') {
            $context->setValue($id, $value);
        }

        return true;
    }

    /** CITY, STAE POST and CTRY of an address as one line; empty when it has none of them. */
    public static function compose(Document $document, int $address, Context $context): string
    {
        $parts = [];
        foreach ($document->childIds($address) as $child) {
            $tag = $document->tagOf($child);
            if (in_array($tag, ['CITY', 'STAE', 'POST', 'CTRY'], true)) {
                $parts[$tag] ??= (string) $context->value($child);
            }
        }
        $region = trim(($parts['STAE'] ?? '') . ' ' . ($parts['POST'] ?? ''));

        return implode(', ', array_filter([$parts['CITY'] ?? '', $region, $parts['CTRY'] ?? ''], static fn(string $part): bool => $part !== ''));
    }

    private function hasSubstructures(Document $document, int $id): bool
    {
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($tag !== 'CONC' && $tag !== 'CONT') {
                return true;
            }
        }

        return false;
    }
}

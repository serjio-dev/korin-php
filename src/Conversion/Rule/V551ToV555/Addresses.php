<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV555;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\V555ToV551\Addresses as Up;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;

/**
 * 5.5.5 ADDR has no value: the address is in ADR1–ADR3, CITY, STAE, POST and CTRY. The lines of
 * the value (at most three) are ADR1–ADR3: ADR lines that repeat them are kept, missing ones
 * are added, and the value is removed. So is a value the conversion back would make from CITY,
 * STAE, POST and CTRY. Any other value (more lines, an empty one, a line other than its ADR)
 * moves to a first substructure _ADDR (KL3001), from which the conversion back restores it.
 *
 * @internal
 */
final class Addresses implements NodeRule
{
    private const AFTER = ['ADR1' => ['ADR2', 'ADR3', 'CITY', 'STAE', 'POST', 'CTRY'], 'ADR2' => ['ADR3', 'CITY', 'STAE', 'POST', 'CTRY'], 'ADR3' => ['CITY', 'STAE', 'POST', 'CTRY']];

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
        if ($document->parentOf($id) === 0 || $context->inExtension($id) || !$context->isFromSource($id)) {
            return true;
        }
        $value = $context->value($id);
        if ($value === null || $value === '') {
            return true;
        }
        $children = [];
        foreach ($document->childIds($id) as $child) {
            $children[$document->tagOf($child)] ??= $child;
        }
        $lines = explode("\n", $value);
        $fits = count($lines) <= 3 && !in_array('', $lines, true);
        foreach (array_keys(self::AFTER) as $i => $tag) {
            $line = $lines[$i] ?? null;
            $existing = isset($children[$tag]) ? $context->value($children[$tag]) : null;
            if ($existing !== null && $existing !== $line) {
                $fits = false;
            }
        }
        $context->setValue($id, null);
        if ($fits && !isset($children['ADR1']) && $value === Up::compose($document, $id, $context)) {
            return true;
        }
        if ($fits) {
            foreach (array_keys(self::AFTER) as $i => $tag) {
                if (isset($lines[$i]) && !isset($children[$tag])) {
                    $context->setValue($document->createNode($id, null, $tag, null, $this->before($document, $id, $tag)), $lines[$i]);
                }
            }

            return true;
        }
        if ($context->options->policy === LossPolicy::DropWithReport) {
            $context->loss('KL3001', Severity::Warning, $id, StoredAs::Dropped, '5.5.5 ADDR has no value');

            return true;
        }
        $context->setValue($document->createNode($id, null, '_ADDR', null, $document->childIds($id)[0] ?? null), $value);
        $context->loss('KL3001', Severity::Warning, $id, StoredAs::Extension, '5.5.5 ADDR has no value');

        return true;
    }

    private function before(Document $document, int $address, string $tag): ?int
    {
        foreach ($document->childIds($address) as $child) {
            if (in_array($document->tagOf($child), self::AFTER[$tag], true)) {
                return $child;
            }
        }

        return null;
    }
}

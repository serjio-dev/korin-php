<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Ages as Upgrade;
use Korin\Gedcom\Syntax\Document;

/**
 * Ages by the 5.5.1 grammar: no space after "<" or ">" ("< 8y" → "<8y"); a PHRASE that is the
 * 5.5.1 age it came from replaces the value ("< 8y" + PHRASE CHILD → CHILD). Every value is one
 * that converts up to the same age and PHRASE again: another PHRASE stays for the structures
 * rule, and an age 5.5.1 cannot write is kept as an extension, _AGE (KL3001): weeks ("2w"), and
 * a PHRASE alone that is no age ("AGE" + PHRASE Zero; "AGE Zero" is not a 5.5.1 age).
 *
 * @internal
 */
final readonly class Ages implements NodeRule
{
    private Upgrade $upgrade;

    public function __construct()
    {
        $this->upgrade = new Upgrade();
    }

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['AGE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (($context->sourceStructure($id)->uri ?? null) !== 'https://gedcom.io/terms/v7/AGE') {
            return true;
        }
        $age = (string) $context->value($id);
        $phrase = null;
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'PHRASE') {
                $phrase = $child;
                break;
            }
        }
        $text = $phrase === null ? null : (string) $context->value($phrase);
        if ($text !== null && $this->up($text) === [$age, $text]) {
            if ($age === '') {
                return $context->unsupported($id, 'KL3001', 'The age has no 5.5.1 form');
            }
            $document->removeNode($phrase);
            $context->setValue($id, $text);

            return true;
        }
        $converted = (string) preg_replace('/^([<>]) /', '$1', $age);
        if ($age !== '' && (str_contains($age, 'w') || $this->up($converted) !== [$age, null])) {
            return $context->unsupported($id, 'KL3001', 'The age has no 5.5.1 form');
        }
        if ($converted !== $age) {
            $context->setValue($id, $converted);
        }

        return true;
    }

    /** @return array{string, ?string} */
    private function up(string $value): array
    {
        return $this->upgrade->convert($value) ?? [$value, null];
    }
}

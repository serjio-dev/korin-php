<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * Walks a record once, in document order, and gives every structure to each of its rules for
 * that tag in turn; CONC/CONT lines are part of their structure's value and are not visited. A structure
 * a rule removes is not given to the rules after it, nor are its substructures.
 *
 * @internal
 */
final readonly class Nodes implements Rule
{
    /** @var list<NodeRule> */
    private array $rules;

    /** @var list<NodeRule> rules for every tag */
    private array $any;

    /** @var array<string, list<NodeRule>> rules by tag, in the order given, rules for every tag included */
    private array $byTag;

    public function __construct(NodeRule ...$rules)
    {
        $this->rules = array_values($rules);
        $this->any = array_values(array_filter($this->rules, static fn(NodeRule $rule): bool => $rule->tags() === null));
        $byTag = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->tags() ?? [] as $tag) {
                $byTag[$tag] = array_values(array_filter($this->rules, static fn(NodeRule $r): bool => $r->tags() === null || in_array($tag, $r->tags(), true)));
            }
        }
        $this->byTag = $byTag;
    }

    public function needsPrepass(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->needsPrepass()) {
                return true;
            }
        }

        return false;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        $stack = [$record];
        while ($stack !== []) {
            $id = array_pop($stack);
            foreach ($this->byTag[$document->tagOf($id)] ?? $this->any as $rule) {
                if (!$rule->node($document, $id, $context)) {
                    continue 2;
                }
            }
            if (!$document->hasChildren($id)) {
                continue;
            }
            $children = $document->childIds($id);
            for ($i = count($children) - 1; $i >= 0; --$i) {
                $tag = $document->tagOf($children[$i]);
                if ($tag !== 'CONC' && $tag !== 'CONT') {
                    $stack[] = $children[$i];
                }
            }
        }
    }
}

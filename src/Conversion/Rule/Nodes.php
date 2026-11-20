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
 * a rule removes is not given to the rules after it, nor are its substructures. An extension a rule
 * makes a structure again is given to the rules of its new tag, that rule but, from the first: those that
 * saw it as an extension passed it by, so a rule for every tag sees it twice.
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
            $tag = $document->tagOf($id);
            $extension = $tag !== '' && $tag[0] === '_';
            foreach ($this->byTag[$tag] ?? $this->any as $rule) {
                if (!$rule->node($document, $id, $context)) {
                    continue 2;
                }
                // An extension a rule made a standard structure again (Extensions: _DATE under NCHI) is given to
                // the rules of its new tag, but that one: the rules before saw an extension, and passed it by.
                if ($extension && !str_starts_with($document->tagOf($id), '_')) {
                    foreach ($this->byTag[$document->tagOf($id)] ?? $this->any as $next) {
                        if ($next !== $rule && !$next->node($document, $id, $context)) {
                            continue 3;
                        }
                    }
                    break;
                }
            }
            if ($document->hasChildren($id)) {
                foreach ($document->substructureIdsReversed($id) as $child) {
                    $stack[] = $child;
                }
            }
        }
    }
}

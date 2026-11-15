<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Syntax\Document;

/**
 * A rule for one structure at a time. Rules that look at every line of a record are grouped in
 * {@see Rule\Nodes}, which walks each record once for all of them.
 *
 * @internal
 */
interface NodeRule
{
    /** Whether the rule needs Context::prepass(): facts about the whole input before the first record. */
    public function needsPrepass(): bool;

    /** @return ?list<string> the tags of the structures the rule converts; null for every structure */
    public function tags(): ?array;

    /**
     * Converts one structure (a record or a substructure; never a CONC/CONT line) with one of
     * the rule's tags, as the structure is tagged when the walk reaches it, in place. Its
     * substructures are visited after it, as they are when this returns.
     *
     * @return bool false when the rule removed the structure: the rules after it and its
     *              substructures are skipped
     */
    public function node(Document $document, int $id, Context $context): bool;
}

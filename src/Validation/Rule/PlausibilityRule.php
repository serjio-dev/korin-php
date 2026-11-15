<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;

/**
 * Level 5, optional: warnings about data that is valid but cannot be true. A birth after the
 * death (KV5001), a child born before a parent (KV5002), an individual who is their own ancestor
 * (KV5003). Dates are compared as DateRange: only when the conflict is certain. The first BIRT
 * and DEAT with such a date count. Parents are HUSB and WIFE of the families an individual is a
 * child of, by FAM.CHIL or INDI.FAMC.
 *
 * @internal
 */
final class PlausibilityRule implements Rule
{
    public function check(Document $document, Profile $profile): array
    {
        $out = [];
        /** @var array<string, int> $records xref => first record with it */
        $records = [];
        foreach ($document->childIds(0) as $id) {
            $xref = $document->xrefOf($id);
            if ($xref !== null && !isset($records[$xref]) && !$document->isInvalid($id)) {
                $records[$xref] = $id;
            }
        }

        /** @var array<int, array{int, array{int, int}}> $births individual => DATE line and its range */
        $births = [];
        /** @var array<int, list<int>> $parents individual => parents */
        $parents = [];
        foreach ($records as $id) {
            match ($document->tagOf($id)) {
                'INDI' => $this->individual($document, $id, $records, $births, $parents, $out),
                'FAM' => $this->family($document, $id, $records, $parents),
                default => null,
            };
        }

        foreach ($records as $id) {
            if ($document->tagOf($id) === 'FAM') {
                $this->childrenBornBeforeParents($document, $id, $records, $births, $out);
            }
        }
        $this->cycles($document, $parents, $out);

        return $out;
    }

    /**
     * @param array<string, int> $records
     * @param array<int, array{int, array{int, int}}> $births
     * @param array<int, list<int>> $parents
     * @param list<Diagnostic> $out
     */
    private function individual(Document $document, int $id, array $records, array &$births, array &$parents, array &$out): void
    {
        $death = null;
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'BIRT' && !isset($births[$id])) {
                $date = $this->date($document, $child);
                if ($date !== null) {
                    $births[$id] = $date;
                }
            } elseif ($tag === 'DEAT' && $death === null) {
                $death = $this->date($document, $child);
            } elseif ($tag === 'FAMC') {
                $family = $records[(string) $document->payloadOf($child)] ?? null;
                if ($family !== null && $document->tagOf($family) === 'FAM') {
                    foreach ($this->spouses($document, $family, $records) as $parent) {
                        $parents[$id][] = $parent;
                    }
                }
            }
        }
        if (isset($births[$id]) && $death !== null && $births[$id][1][0] > $death[1][1]) {
            $out[] = $this->diagnostic($document, 'KV5001', 'Birth is after the death on line ' . $document->lineOf($death[0]), $births[$id][0]);
        }
    }

    /**
     * @param array<string, int> $records
     * @param array<int, list<int>> $parents
     */
    private function family(Document $document, int $id, array $records, array &$parents): void
    {
        $spouses = $this->spouses($document, $id, $records);
        foreach ($this->children($document, $id, $records) as $child) {
            foreach ($spouses as $parent) {
                $parents[$child[1]][] = $parent;
            }
        }
    }

    /**
     * @param array<string, int> $records
     * @param array<int, array{int, array{int, int}}> $births
     * @param list<Diagnostic> $out
     */
    private function childrenBornBeforeParents(Document $document, int $family, array $records, array $births, array &$out): void
    {
        $spouses = [];
        foreach ($document->childIds($family) as $line) {
            $tag = $document->tagOf($line);
            $parent = $records[(string) $document->payloadOf($line)] ?? null;
            if (($tag === 'HUSB' || $tag === 'WIFE') && $parent !== null && isset($births[$parent])) {
                $spouses[$tag] = $parent;
            }
        }
        foreach ($this->children($document, $family, $records) as [$line, $child]) {
            if (!isset($births[$child])) {
                continue;
            }
            foreach ($spouses as $tag => $parent) {
                if ($births[$child][1][1] < $births[$parent][1][0]) {
                    $out[] = $this->diagnostic($document, 'KV5002', "The child is born before {$tag} " . $document->xrefOf($parent), $line);
                }
            }
        }
    }

    /**
     * Individuals who are their own ancestors: strongly connected components of the graph
     * child → parent (Tarjan's algorithm without recursion), each reported once, at its first
     * individual.
     *
     * @param array<int, list<int>> $parents
     * @param list<Diagnostic> $out
     */
    private function cycles(Document $document, array $parents, array &$out): void
    {
        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $next = 0;
        foreach (array_keys($parents) as $start) {
            if (isset($index[$start])) {
                continue;
            }
            /** @var list<int> $work the path being explored */
            $work = [$start];
            /** @var array<int, int> $visited position of the next parent to visit, by individual */
            $visited = [$start => 0];
            $index[$start] = $low[$start] = $next++;
            $stack[] = $start;
            $onStack[$start] = true;
            while ($work !== []) {
                $node = $work[count($work) - 1];
                $edges = $parents[$node] ?? [];
                $position = $visited[$node];
                if ($position < count($edges)) {
                    $visited[$node] = $position + 1;
                    $parent = $edges[$position];
                    if (!isset($index[$parent])) {
                        $index[$parent] = $low[$parent] = $next++;
                        $stack[] = $parent;
                        $onStack[$parent] = true;
                        $visited[$parent] = 0;
                        $work[] = $parent;
                    } elseif (isset($onStack[$parent])) {
                        $low[$node] = min($low[$node], $index[$parent]);
                    }
                    continue;
                }
                array_pop($work);
                if ($work !== []) {
                    $caller = $work[count($work) - 1];
                    $low[$caller] = min($low[$caller], $low[$node]);
                }
                if ($low[$node] !== $index[$node]) {
                    continue;
                }
                $size = 0;
                $first = $node;
                do {
                    $member = array_pop($stack) ?? $node; // the stack holds $node until here
                    unset($onStack[$member]);
                    ++$size;
                    $first = min($first, $member);
                } while ($member !== $node);
                if ($size > 1 || in_array($node, $edges, true)) {
                    $out[] = $this->diagnostic($document, 'KV5003', "The individual is their own ancestor (a cycle of {$size} individual" . ($size > 1 ? 's' : '') . ')', $first);
                }
            }
        }
    }

    /**
     * @param array<string, int> $records
     *
     * @return list<int> the individuals HUSB and WIFE of a family point to
     */
    private function spouses(Document $document, int $family, array $records): array
    {
        $spouses = [];
        foreach ($document->childIds($family) as $line) {
            $tag = $document->tagOf($line);
            if ($tag === 'HUSB' || $tag === 'WIFE') {
                $individual = $this->individualAt($document, $line, $records);
                if ($individual !== null) {
                    $spouses[] = $individual;
                }
            }
        }

        return $spouses;
    }

    /**
     * @param array<string, int> $records
     *
     * @return list<array{int, int}> CHIL lines and the individuals they point to
     */
    private function children(Document $document, int $family, array $records): array
    {
        $children = [];
        foreach ($document->childIds($family) as $line) {
            if ($document->tagOf($line) === 'CHIL') {
                $individual = $this->individualAt($document, $line, $records);
                if ($individual !== null) {
                    $children[] = [$line, $individual];
                }
            }
        }

        return $children;
    }

    /** @param array<string, int> $records */
    private function individualAt(Document $document, int $line, array $records): ?int
    {
        $record = $records[(string) $document->payloadOf($line)] ?? null;

        return $record !== null && $document->tagOf($record) === 'INDI' ? $record : null;
    }

    /** @return ?array{int, array{int, int}} the DATE line of an event and its range */
    private function date(Document $document, int $event): ?array
    {
        foreach ($document->childIds($event) as $child) {
            if ($document->tagOf($child) === 'DATE') {
                $range = DateRange::of((string) $document->node($child)->value());

                return $range === null ? null : [$child, $range];
            }
        }

        return null;
    }

    private function diagnostic(Document $document, string $code, string $message, int $id): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, Severity::Warning, $message, $line > 0 ? $line : null, $document->node($id)->path());
    }
}

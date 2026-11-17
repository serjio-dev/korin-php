<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Records\Records;

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
    private const PARTNERS = ['HUSB', 'WIFE'];

    private Records $records;

    // Packed for memory: the state of a file of any size, a few integers per individual.

    /** @var array<int, int> individual => start of the range of the first BIRT date */
    private array $bornFrom = [];

    /** @var array<int, int> individual => end of that range */
    private array $bornTo = [];

    /** @var array<int, string> individual => parents, 32-bit keys */
    private array $parents = [];

    /** INDI.FAMC, 32-bit: individual, family; their parents are added at the end, when the families are known. */
    private string $childOf = '';

    /** @var array<int, string> family => the individuals HUSB and WIFE point to, 32-bit */
    private array $spousesOf = [];

    /** @var list<string> families, 32-bit: key, number of HUSB and WIFE, each (PARTNERS index, record), then each CHIL (node, individual) */
    private array $families = [];

    /** @var list<Diagnostic> */
    private array $out = [];

    public function begin(Records $records, Profile $profile): void
    {
        $this->records = $records;
        $this->reset();
    }

    public function record(Document $document, int $id, int $key): void
    {
        // The first record with an xref; the others are reported as duplicates (KV4002).
        $xref = $document->xrefOf($id);
        if ($xref === null || $document->isInvalid($id) || $this->records->find($xref) !== $key) {
            return;
        }
        match ($document->tagOf($id)) {
            'INDI' => $this->individual($document, $id, $key),
            'FAM' => $this->family($document, $id, $key),
            default => null,
        };
    }

    public function end(): array
    {
        foreach ($this->families as $family) {
            $this->childrenBornBeforeParents($this->ints($family));
        }
        $pairs = $this->ints($this->childOf);
        for ($i = 0, $n = count($pairs); $i < $n; $i += 2) {
            foreach ($this->ints($this->spousesOf[$pairs[$i + 1]] ?? '') as $parent) {
                $this->addParent($pairs[$i], $parent);
            }
        }
        $this->cycles($this->out);
        $out = $this->out;
        $this->reset();

        return $out;
    }

    private function reset(): void
    {
        $this->bornFrom = $this->bornTo = $this->parents = $this->spousesOf = $this->families = $this->out = [];
        $this->childOf = '';
    }

    private function individual(Document $document, int $id, int $key): void
    {
        $birth = $death = null;
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'BIRT' && $birth === null) {
                $birth = $this->date($document, $child);
            } elseif ($tag === 'DEAT' && $death === null) {
                $death = $this->date($document, $child);
            } elseif ($tag === 'FAMC') {
                $family = $this->records->find((string) $document->payloadOf($child));
                if ($family !== null && $this->records->tag($family) === 'FAM') {
                    $this->childOf .= pack('NN', $key, $family);
                }
            }
        }
        if ($birth !== null) {
            [$this->bornFrom[$key], $this->bornTo[$key]] = $birth[1];
            if ($death !== null && $birth[1][0] > $death[1][1]) {
                $this->out[] = $this->diagnostic($document, 'KV5001', 'Birth is after the death on line ' . $document->lineOf($death[0]), $birth[0]);
            }
        }
    }

    private function family(Document $document, int $id, int $key): void
    {
        $spouses = $this->spouses($document, $id);
        $this->spousesOf[$key] = pack('N*', ...$spouses);
        $partners = [];
        $children = '';
        foreach ($document->childIds($id) as $line) {
            $tag = $document->tagOf($line);
            if ($tag === 'HUSB' || $tag === 'WIFE') {
                $record = $this->records->find((string) $document->payloadOf($line));
                if ($record !== null) {
                    $partners[] = $tag === 'HUSB' ? 0 : 1;
                    $partners[] = $record;
                }
            } elseif ($tag === 'CHIL') {
                $individual = $this->individualAt($document, $line);
                if ($individual !== null) {
                    $children .= pack('NN', $line, $individual);
                    foreach ($spouses as $parent) {
                        $this->addParent($individual, $parent);
                    }
                }
            }
        }
        $this->families[] = pack('NN', $key, count($partners) >> 1) . pack('N*', ...$partners) . $children;
    }

    /** @return list<int> */
    private function ints(string $packed): array
    {
        $ints = [];
        foreach ($packed === '' ? [] : (array) unpack('N*', $packed) as $int) {
            if (is_int($int)) {
                $ints[] = $int;
            }
        }

        return $ints;
    }

    private function addParent(int $child, int $parent): void
    {
        $this->parents[$child] = ($this->parents[$child] ?? '') . pack('N', $parent);
    }

    /** @param list<int> $family as packed in {@see self::$families} */
    private function childrenBornBeforeParents(array $family): void
    {
        // The last HUSB and the last WIFE with a birth date, in the order the tags first occur.
        $spouses = [];
        $end = 2 + 2 * $family[1];
        for ($i = 2; $i < $end; $i += 2) {
            if (isset($this->bornFrom[$family[$i + 1]])) {
                $spouses[self::PARTNERS[$family[$i]]] = $family[$i + 1];
            }
        }
        for ($i = $end, $n = count($family); $i < $n; $i += 2) {
            $child = $family[$i + 1];
            if (!isset($this->bornTo[$child])) {
                continue;
            }
            foreach ($spouses as $tag => $parent) {
                if ($this->bornTo[$child] < $this->bornFrom[$parent]) {
                    [$document] = $this->records->get($family[0]);
                    [$parentDocument, $parentId] = $this->records->get($parent);
                    $this->out[] = $this->diagnostic($document, 'KV5002', "The child is born before {$tag} " . $parentDocument->xrefOf($parentId), $family[$i]);
                }
            }
        }
    }

    /**
     * Individuals who are their own ancestors: strongly connected components of the graph
     * child → parent (Tarjan's algorithm without recursion), each reported once, at its first
     * individual.
     *
     * @param list<Diagnostic> $out
     */
    private function cycles(array &$out): void
    {
        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $next = 0;
        /** @var array<int, list<int>> $edgesOf parents of the individuals on the path */
        $edgesOf = [];
        foreach (array_keys($this->parents) as $start) {
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
                $edges = $edgesOf[$node] ??= $this->ints($this->parents[$node] ?? '');
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
                unset($edgesOf[$node]);
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
                    [$document, $id] = $this->records->get($first);
                    $out[] = $this->diagnostic($document, 'KV5003', "The individual is their own ancestor (a cycle of {$size} individual" . ($size > 1 ? 's' : '') . ')', $id);
                }
            }
        }
    }

    /** @return list<int> the individuals HUSB and WIFE of a family point to */
    private function spouses(Document $document, int $family): array
    {
        $spouses = [];
        foreach ($document->childIds($family) as $line) {
            $tag = $document->tagOf($line);
            if ($tag === 'HUSB' || $tag === 'WIFE') {
                $individual = $this->individualAt($document, $line);
                if ($individual !== null) {
                    $spouses[] = $individual;
                }
            }
        }

        return $spouses;
    }

    private function individualAt(Document $document, int $line): ?int
    {
        $record = $this->records->find((string) $document->payloadOf($line));

        return $record !== null && $this->records->tag($record) === 'INDI' ? $record : null;
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

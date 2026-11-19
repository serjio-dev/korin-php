<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Corpus;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Tests\Support\Corpus;
use Korin\Gedcom\Tests\Support\Fingerprint;
use Korin\Gedcom\Validation\Rule\Schema;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SPEC section 10, test type 5: converting every 7.0 input of the corpus to 5.5.1, 5.5 or 5.5.5 and
 * back gives the same fingerprint, except for the structures the loss report names with a KL3
 * code (values the older version cannot write so that they come back). Percent-encodings of
 * FILE compare in uppercase, as URLs do; a record whose tag HEAD.SCHMA documents as a standard
 * record compares as that record (_USER is SUBM, which 5.x writes). Via 5.5.5 the SUBM records
 * move directly after HEAD and HEAD.SUBM is added, ADDR gets its lines as ADR1–ADR3 and times
 * lose a leading zero (docs/conversion.md), so records compare in any order, an added HEAD.SUBM
 * is left out, ADR lines that repeat the ADDR value compare as absent and times compare as the
 * same time ("08:56" is "8:56", ".50" is ".5").
 */
final class UpDownUpTest extends TestCase
{
    /** @return iterable<string, array{string, Version}> */
    public static function inputs(): iterable
    {
        foreach (Corpus::cases() as $dir => $case) {
            /** @var array{input: array{file: string, version: string}} $case */
            if ($case['input']['version'] === '7.0' && str_ends_with($case['input']['file'], '.ged')) {
                foreach ([Version::V5_5_1, Version::V5_5, Version::V5_5_5] as $via) {
                    yield basename($dir) . ' via ' . $via->value => [$dir . '/' . $case['input']['file'], $via];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('inputs')]
    public function upDownUpKeepsTheDocument(string $file, Version $via): void
    {
        $input = Gedcom::parse((string) file_get_contents($file));
        $down = Gedcom::convert($input, $via);
        $up = Gedcom::convert($down->document(), Version::V7_0)->document()->copy();
        $before = $input->copy();
        $chains = $paths = [];
        foreach ($down->losses()->entries() as $loss) {
            if (!str_starts_with($loss->code, 'KL3')) {
                continue;
            }
            $paths = $paths !== [] ? $paths : $this->paths($before);
            $id = $paths[$loss->path] ?? null;
            self::assertNotNull($id, "the loss path {$loss->path} names a structure");
            $chains[$id] = $this->chain($before, $id);
        }
        // From the last: removing a structure moves the ones after it.
        rsort($chains);
        foreach ($chains as $chain) {
            $before->removeNode($this->at($before, $chain));
            $up->removeNode($this->at($up, $chain));
        }

        $head = $input->head();
        $aliases = $head === null ? [] : Schema::of($input, $head->id(), Profile::for(Version::V7_0))->records;
        $anyOrder = $via === Version::V5_5_5;
        if ($anyOrder && $head?->first('SUBM') === null) {
            $up->head()?->first('SUBM')?->remove();
        }
        self::assertSame($this->fingerprint($before, $aliases, $anyOrder), $this->fingerprint($up, $aliases, $anyOrder), 'Losses: ' . implode('; ', array_map(strval(...), $down->losses()->entries())));
    }

    /** @param array<string, string> $aliases extension record tag => the standard record tag */
    private function fingerprint(Document $document, array $aliases, bool $anyOrder): string
    {
        $fingerprint = Fingerprint::of($document);
        /** @var list<array{tag: string}> $records */
        $records = $fingerprint['records'];
        foreach ($records as &$record) {
            $record['tag'] = $aliases[$record['tag']] ?? $record['tag'];
        }
        unset($record);
        if ($anyOrder) {
            usort($records, static fn(array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));
        }
        $fingerprint['records'] = $anyOrder ? array_map($this->spelled(...), $records) : $records;
        $json = (string) json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) preg_replace_callback('/("tag": "FILE",\n\s*"value": ")([^"]*)/', static fn(array $m): string => $m[1] . preg_replace_callback('/%[0-9a-f]{2}/i', static fn(array $e): string => strtoupper($e[0]), $m[2]), $json);
    }

    /**
     * A structure of a fingerprint without what 5.5.5 spells differently: ADR lines that repeat
     * the ADDR value, a leading zero of an hour, trailing zeroes of a fraction of a second.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function spelled(array $node): array
    {
        if (($node['tag'] ?? null) === 'TIME' && is_string($node['value'] ?? null)) {
            $node['value'] = (string) preg_replace(['/^0(?=[0-9]:)/', '/(\.[0-9]*?)0+(?=Z?$)/', '/\.(?=Z?$)/'], ['', '$1', ''], $node['value']);
        }
        /** @var list<array<string, mixed>> $children */
        $children = $node['children'] ?? [];
        if (($node['tag'] ?? null) === 'ADDR') {
            $lines = explode("\n", is_string($node['value'] ?? null) ? $node['value'] : '');
            $children = array_values(array_filter($children, static fn(array $child): bool => !in_array($child['tag'] ?? null, ['ADR1', 'ADR2', 'ADR3'], true)
                || ($child['children'] ?? []) !== [] || ($lines[(int) substr($child['tag'], 3) - 1] ?? null) !== ($child['value'] ?? null)));
        }
        if ($children !== []) {
            $node['children'] = array_map($this->spelled(...), $children);
        } else {
            unset($node['children']);
        }

        return $node;
    }

    /** @return array<string, int> the structures of a document by path */
    private function paths(Document $document): array
    {
        $paths = [];
        foreach ($document->walk() as $id) {
            if (!$document->isInvalid($id)) {
                $paths[$document->node($id)->path()] ??= $id;
            }
        }

        return $paths;
    }

    /**
     * @return non-empty-list<int|string> the position of a node: its record (by xref, or by tag and
     *                                    number among the records of that tag without one: 5.5.5
     *                                    moves records), then its index among its parent's children
     */
    private function chain(Document $document, int $id): array
    {
        $chain = [];
        for (; $document->parentOf($id) !== 0; $id = $document->parentOf($id)) {
            array_unshift($chain, (int) array_search($id, $document->childIds($document->parentOf($id)), true));
        }
        array_unshift($chain, $this->key($document, $id));

        return $chain;
    }

    /** @param non-empty-list<int|string> $chain */
    private function at(Document $document, array $chain): int
    {
        $key = array_shift($chain);
        $id = null;
        foreach ($document->childIds(0) as $record) {
            if ($this->key($document, $record) === $key) {
                $id = $record;
                break;
            }
        }
        self::assertNotNull($id, "the record {$key} is in both documents");
        foreach ($chain as $index) {
            $id = $document->childIds($id)[(int) $index];
        }

        return $id;
    }

    private function key(Document $document, int $record): string
    {
        $xref = $document->xrefOf($record);
        if ($xref !== null) {
            return $xref;
        }
        $tag = $document->tagOf($record);
        $number = 0;
        foreach ($document->childIds(0) as $other) {
            if ($other === $record) {
                break;
            }
            $number += $document->tagOf($other) === $tag && $document->xrefOf($other) === null ? 1 : 0;
        }

        return "{$tag}#{$number}";
    }
}

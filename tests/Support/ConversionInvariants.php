<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\Schema;
use Korin\Gedcom\Version;

/** Invariants of conversions, checked on the corpus (MatrixTest, UpDownUpTest) and on random documents. */
trait ConversionInvariants
{
    /**
     * Errors a valid input may still give, by target: code, a pattern of the message and, when only
     * an input of some versions gives it, those versions (docs/conversion.md, "Conversion matrix").
     * 5.x requires a submitter a 7.0 file need not name; 5.x lists few media formats (FORM png
     * stays); a 5.5 media record holds the object itself (BLOB), not a file, and the other way
     * round; 7.0 and 5.5.5 require a TYPE that FACT and IDNO need not have in 5.5.1, and 7.0 one
     * for an EVEN without a value; a 7.0 RESN list stays a list; 5.5.5 lengths are not converted;
     * 5.5 does not require the NAME of a repository, nor the DATE of an LDS STAT.
     */
    private const LIMITS = [
        '5.5' => [['KV2003', '/^HEAD requires SUBM$/'], ['KV3006', '/^FORM does not permit/'], ['KV2003', '/^OBJE requires BLOB$/'], ['KV3006', '/^RESN does not permit "[^"]*,/']],
        '5.5.1' => [['KV2003', '/^HEAD requires SUBM$/'], ['KV3006', '/^FORM does not permit/'], ['KV3006', '/^RESN does not permit "[^"]*,/'], ...self::FROM_5_5],
        '5.5.5' => [['KV2003', '/^The file requires a SUBM record$/'], ['KV3006', '/^FORM does not permit/'], ['KV2003', '/^(FACT|IDNO) requires TYPE$/'], ['KV3010', '/ is (longer|shorter) than /'], ...self::FROM_5_5],
        '7.0' => [['KV2003', '/^(FACT|IDNO|EVEN) requires TYPE$/'], ...self::FROM_5_5],
    ];

    private const FROM_5_5 = [
        ['KV2003', '/^OBJE requires FILE$/', ['4.0', '5.5']],
        ['KV2003', '/^REPO requires NAME$/', ['4.0', '5.5']],
        ['KV2003', '/^STAT requires DATE$/', ['4.0', '5.5']],
    ];

    /**
     * SPEC section 10, kind 4: the output validates without errors, other than those the input has
     * ({@see self::sameErrors()}) and the documented limits of the target (docs/conversion.md); the conversion is deterministic,
     * converting the output to its own version changes nothing, and convertFile() gives the bytes and the
     * losses of convert().
     *
     * @param list<array{string, string}> $known errors of known bugs the output may also have: code and a pattern of the message
     */
    private function assertConvertsCleanly(string $bytes, Version $to, array $known = []): void
    {
        $input = Gedcom::parse($bytes);
        $result = Gedcom::convert($input, $to);
        $again = Gedcom::convert(Gedcom::parse($bytes), $to);

        self::assertSame($result->output(), $again->output(), 'Deterministic output');
        self::assertSame(array_map(strval(...), $result->losses()->entries()), array_map(strval(...), $again->losses()->entries()), 'Deterministic loss report');
        self::assertSame($result->output(), Gedcom::convert($result->document(), $to)->output(), 'Converting to its own version changes nothing');
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        fwrite($in, $bytes);
        rewind($in);
        $losses = Gedcom::convertFile($in, $out, $to);
        rewind($out);
        self::assertSame($result->output(), stream_get_contents($out), 'convertFile() writes the output of convert()');
        self::assertSame(json_encode($result->losses()), json_encode($losses), 'convertFile() reports the losses of convert()');

        $unexpected = array_filter(
            $this->sameErrors(Gedcom::validate($input)->errors(), Gedcom::validate($result->document())->errors()),
            fn(Diagnostic $error): bool => !$this->isLimit($input->version(), $to, $error, $known),
        );
        self::assertSame([], array_values(array_map(strval(...), $unexpected)), 'The output must validate without errors');
    }

    /**
     * The errors of the output that are not errors of the input: an error of the input stays one at
     * the structure it is at, with its code. That is the same path (any number of errors of the
     * structure; the message may name what the version expects), or, where the conversion moved
     * the structure or renamed its record, the same message, once for every error of the input:
     * an inline OBJE that 5.5.5 and 7.0 make a record loses the form its message names.
     *
     * @param list<Diagnostic> $input
     * @param list<Diagnostic> $output
     *
     * @return list<Diagnostic>
     */
    private function sameErrors(array $input, array $output): array
    {
        $message = static fn(Diagnostic $error): string => $error->code . ' ' . str_replace([' without a pointer', ' with a pointer'], '', $error->message);
        $paths = $messages = [];
        foreach ($input as $error) {
            $paths[$error->code . ' ' . $error->path] = true;
            $messages[$message($error)] = ($messages[$message($error)] ?? 0) + 1;
        }
        $left = [];
        foreach ($output as $error) {
            if (isset($paths[$error->code . ' ' . $error->path])) {
                continue;
            }
            if (($messages[$message($error)] ?? 0) > 0) {
                --$messages[$message($error)];
                continue;
            }
            $left[] = $error;
        }

        return $left;
    }

    /** @param list<array{string, string}> $known */
    private function isLimit(Version $from, Version $to, Diagnostic $error, array $known): bool
    {
        foreach ([...self::LIMITS[$to->value] ?? [], ...$known] as $limit) {
            if ($error->code === $limit[0] && preg_match($limit[1], $error->message) === 1 && in_array($from->value, $limit[2] ?? [$from->value], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SPEC section 10, kind 5: converting a 7.0 document to an older version and back gives the same
     * fingerprint, except for the structures the loss report names with a KL3 code (values the older version
     * cannot write so that they come back). Percent-encodings of FILE compare in uppercase, as URLs do; a
     * record whose tag HEAD.SCHMA documents as a standard record compares as that record (_USER is SUBM,
     * which 5.x writes). Via 5.5.5 the SUBM records move directly after HEAD and HEAD.SUBM is added, ADDR
     * gets its lines as ADR1–ADR3 and times lose a leading zero (docs/conversion.md), so records compare in
     * any order, an added HEAD.SUBM is left out, ADR lines that repeat the ADDR value compare as absent and
     * times compare as the same time ("08:56" is "8:56", ".50" is ".5"), ages with 12 months or more as
     * years and months ("1y 30m" is "3y 6m").
     */
    /** @param list<array{string, string}> $known losses of known bugs whose structures are left out as well: code and a pattern of the message */
    private function assertUpDownUp(string $bytes, Version $via, array $known = []): void
    {
        $input = Gedcom::parse($bytes);
        $down = Gedcom::convert($input, $via);
        $up = Gedcom::convert($down->document(), Version::V7_0)->document()->copy();
        $before = $input->copy();
        $chains = $paths = [];
        foreach ($down->losses()->entries() as $loss) {
            if (!str_starts_with($loss->code, 'KL3') && array_filter($known, static fn(array $k): bool => $loss->code === $k[0] && preg_match($k[1], $loss->message) === 1) === []) {
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
        $records = $anyOrder ? array_map($this->spelled(...), $records) : $records;
        $fingerprint['records'] = array_map($this->ordered(...), $records);
        $json = (string) json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) preg_replace_callback('/("tag": "FILE",\n\s*"value": ")([^"]*)/', static fn(array $m): string => $m[1] . preg_replace_callback('/%[0-9a-f]{2}/i', static fn(array $e): string => strtoupper($e[0]), $m[2]), $json);
    }

    /**
     * A structure of a fingerprint with its substructures by tag: 7.0 gives meaning only to the order of
     * substructures with the same tag (the first is preferred), which the sort keeps. A conversion that
     * writes a value as a substructure (a DATE phrase of 5.5.1 becomes PHRASE) adds it after the others.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function ordered(array $node): array
    {
        /** @var list<array{tag: string}> $children */
        $children = $node['children'] ?? [];
        if ($children !== []) {
            usort($children, static fn(array $a, array $b): int => strcmp($a['tag'], $b['tag']));
            $node['children'] = array_map($this->ordered(...), $children);
        }

        return $node;
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
        if (($node['tag'] ?? null) === 'AGE' && is_string($node['value'] ?? null)
            && preg_match('/^(\S*?) ?(?:([0-9]+)y)? ?(?:([0-9]+)m)?( [0-9]+d)?$/', $node['value'], $m) === 1 && ($m[3] ?? '') !== '') {
            // 5.5.5 has months up to 11: 1y 30m comes back as 3y 6m.
            $years = (int) $m[2] + intdiv((int) $m[3], 12);
            $node['value'] = trim($m[1] . ' ' . ($years > 0 ? $years . 'y ' : '') . ((int) $m[3] % 12 > 0 ? ((int) $m[3] % 12) . 'm' : '') . ($m[4] ?? ''));
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
     * @return non-empty-list<string> the position of a node: its record (by xref, or by tag and number
     *                                among the records of that tag without one: 5.5.5 moves records),
     *                                then its tag and number among the children of its parent with
     *                                that tag (a conversion may move substructures of other tags), a
     *                                tag and its extension (AGE, _AGE: a KL3 loss) counting as one
     */
    private function chain(Document $document, int $id): array
    {
        $chain = [];
        for (; $document->parentOf($id) !== 0; $id = $document->parentOf($id)) {
            $tag = ltrim($document->tagOf($id), '_');
            $same = $this->tagged($document, $document->parentOf($id), $tag);
            // Zero-padded, so that the chains sort from the last.
            array_unshift($chain, sprintf('%s#%05d', $tag, (int) array_search($id, $same, true)));
        }
        array_unshift($chain, $this->key($document, $id));

        return $chain;
    }

    /** @param non-empty-list<string> $chain */
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
        foreach ($chain as $position) {
            [$tag, $number] = explode('#', $position);
            $same = $this->tagged($document, $id, $tag);
            self::assertArrayHasKey((int) $number, $same, "{$position} is in both documents");
            $id = $same[(int) $number];
        }

        return $id;
    }

    /** @return list<int> the children with the tag or the tag of its extension */
    private function tagged(Document $document, int $parent, string $tag): array
    {
        return array_values(array_filter($document->childIds($parent), static fn(int $child): bool => ltrim($document->tagOf($child), '_') === $tag));
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

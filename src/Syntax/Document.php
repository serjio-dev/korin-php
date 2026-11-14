<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\DetachedNodeException;
use Korin\Gedcom\Exception\InvalidPathException;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Version;

/**
 * Lossless, mutable GEDCOM tree. Nodes live in parallel arrays indexed by a stable id
 * (0 is the virtual root whose children are the records); {@see Node} objects are thin
 * views created on demand. Unmodified lines keep their original bytes, so preserve-mode
 * writing reproduces the input exactly. Removed nodes become tombstones and keep their id.
 */
final class Document
{
    private const ROOT = 0;

    /** @var array<int, int> */
    private array $parent = [self::ROOT => -1];

    /** @var array<int, int> */
    private array $depth = [self::ROOT => -1];

    /** @var array<int, ?string> */
    private array $xref = [self::ROOT => null];

    /** @var array<int, string> empty for lines that could not be parsed */
    private array $tag = [self::ROOT => ''];

    /** @var array<int, ?string> line value as in the file (escaped), decoded to UTF-8 */
    private array $payload = [self::ROOT => null];

    /** @var array<int, int> */
    private array $firstChild = [self::ROOT => -1];

    /** @var array<int, int> */
    private array $lastChild = [self::ROOT => -1];

    /** @var array<int, int> */
    private array $next = [self::ROOT => -1];

    /** @var array<int, int> */
    private array $prev = [self::ROOT => -1];

    /** @var array<int, int> byte offset of the original line, -1 for new nodes */
    private array $offset = [self::ROOT => -1];

    /** @var array<int, int> */
    private array $contentLength = [self::ROOT => 0];

    /** @var array<int, int> */
    private array $eolLength = [self::ROOT => 0];

    /** @var array<int, int> 1-based source line, 0 for new nodes */
    private array $line = [self::ROOT => 0];

    /** @var array<int, true> */
    private array $dirty = [];

    /** @var array<int, true> */
    private array $removed = [];

    /** @var array<int, true> lines kept verbatim because they could not be parsed */
    private array $invalid = [];

    /** @var array<string, int> */
    private array $xrefIndex = [];

    /**
     * @internal use {@see \Korin\Gedcom\Gedcom::parse()} or {@see self::create()}
     *
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        private Version $version,
        private readonly Encoding $encoding,
        private readonly bool $bom,
        private string $lineEnding,
        private readonly string $source = '',
        private array $diagnostics = [],
    ) {}

    /** A new, empty document: HEAD with GEDC.VERS, and TRLR. */
    public static function create(Version $version = Version::V7_0): self
    {
        $document = new self($version, Encoding::Utf8, $version === Version::V7_0 || $version === Version::V5_5_5, "\n");
        $head = $document->createNode(self::ROOT, null, 'HEAD', null);
        $gedc = $document->createNode($head, null, 'GEDC', null);
        $document->createNode($gedc, null, 'VERS', $version->value);
        if ($version !== Version::V7_0) {
            $document->createNode($gedc, null, 'FORM', 'LINEAGE-LINKED');
            $document->createNode($head, null, 'CHAR', 'UTF-8');
        }
        $document->createNode(self::ROOT, null, 'TRLR', null);

        return $document;
    }

    public function version(): Version
    {
        return $this->version;
    }

    public function encoding(): Encoding
    {
        return $this->encoding;
    }

    public function hasBom(): bool
    {
        return $this->bom;
    }

    /** Dominant line terminator of the input; used for lines written anew. */
    public function lineEnding(): string
    {
        return $this->lineEnding;
    }

    /** @return list<Diagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return list<Node> all records in order, HEAD and TRLR included */
    public function records(): array
    {
        return array_map($this->node(...), $this->childIds(self::ROOT));
    }

    public function record(string $xref): ?Node
    {
        return isset($this->xrefIndex[$xref]) ? $this->node($this->xrefIndex[$xref]) : null;
    }

    public function head(): ?Node
    {
        return $this->firstRecord('HEAD');
    }

    public function find(string $path): ?Node
    {
        $parsed = Path::parse($path);
        $node = str_starts_with($parsed->record, '@') ? $this->record($parsed->record) : $this->firstRecord($parsed->record);
        foreach ($parsed->segments as [$tag, $index]) {
            if ($node === null) {
                return null;
            }
            $matches = $node->all($tag);
            if ($index === null && count($matches) > 1) {
                throw new InvalidPathException("Path {$path} is ambiguous: {$tag} occurs " . count($matches) . ' times');
            }
            $node = $matches[$index ?? 0] ?? null;
        }

        return $node;
    }

    /** Adds a record before TRLR (or at the end when there is none). */
    public function addRecord(string $tag, ?string $xref = null, ?string $value = null): Node
    {
        if ($xref !== null && isset($this->xrefIndex[$xref])) {
            throw new \InvalidArgumentException("Xref {$xref} is already used");
        }
        $trailer = $this->firstRecord('TRLR');
        $id = $this->createNode(self::ROOT, $xref, $tag, null, $trailer?->id());
        $node = $this->node($id);
        if ($value !== null) {
            $node->setValue($value);
        }

        return $node;
    }

    public function removeRecord(string $xref): void
    {
        $this->record($xref)?->remove();
    }

    /** Renames a record's xref and every pointer to it. */
    public function renameXref(string $from, string $to): void
    {
        if (!isset($this->xrefIndex[$from])) {
            throw new \InvalidArgumentException("No record with xref {$from}");
        }
        if (isset($this->xrefIndex[$to])) {
            throw new \InvalidArgumentException("Xref {$to} is already used");
        }
        $id = $this->xrefIndex[$from];
        $this->xref[$id] = $to;
        $this->dirty[$id] = true;
        unset($this->xrefIndex[$from]);
        $this->xrefIndex[$to] = $id;
        foreach ($this->payload as $node => $payload) {
            if ($payload === $from && !isset($this->removed[$node])) {
                $this->payload[$node] = $to;
                $this->dirty[$node] = true;
            }
        }
    }

    /** Cheap independent snapshot: arrays are copied on write. */
    public function copy(): self
    {
        return clone $this;
    }

    // ---- Internal API used by Node, the parser and writers -------------------------------

    /** @internal */
    public function node(int $id): Node
    {
        return new Node($this, $id);
    }

    /**
     * @internal
     *
     * @param ?int $before sibling to insert before; null appends
     */
    public function createNode(
        int $parent,
        ?string $xref,
        string $tag,
        ?string $payload,
        ?int $before = null,
        int $offset = -1,
        int $contentLength = 0,
        int $eolLength = 0,
        int $line = 0,
        bool $invalid = false,
    ): int {
        $id = count($this->parent);
        $this->parent[$id] = $parent;
        $this->depth[$id] = $this->depth[$parent] + 1;
        $this->xref[$id] = $xref;
        $this->tag[$id] = $tag;
        $this->payload[$id] = $payload;
        $this->firstChild[$id] = -1;
        $this->lastChild[$id] = -1;
        $this->offset[$id] = $offset;
        $this->contentLength[$id] = $contentLength;
        $this->eolLength[$id] = $eolLength;
        $this->line[$id] = $line;
        if ($offset < 0) {
            $this->dirty[$id] = true;
        }
        if ($invalid) {
            $this->invalid[$id] = true;
        }
        if ($before === null) {
            $this->prev[$id] = $this->lastChild[$parent];
            $this->next[$id] = -1;
            if ($this->lastChild[$parent] >= 0) {
                $this->next[$this->lastChild[$parent]] = $id;
            } else {
                $this->firstChild[$parent] = $id;
            }
            $this->lastChild[$parent] = $id;
        } else {
            $this->prev[$id] = $this->prev[$before];
            $this->next[$id] = $before;
            if ($this->prev[$before] >= 0) {
                $this->next[$this->prev[$before]] = $id;
            } else {
                $this->firstChild[$parent] = $id;
            }
            $this->prev[$before] = $id;
        }
        if ($parent === self::ROOT && $xref !== null) {
            $this->xrefIndex[$xref] ??= $id;
        }

        return $id;
    }

    /** @internal */
    public function removeNode(int $id): void
    {
        $this->assertAttached($id);
        $parent = $this->parent[$id];
        $prev = $this->prev[$id];
        $next = $this->next[$id];
        if ($prev >= 0) {
            $this->next[$prev] = $next;
        } else {
            $this->firstChild[$parent] = $next;
        }
        if ($next >= 0) {
            $this->prev[$next] = $prev;
        } else {
            $this->lastChild[$parent] = $prev;
        }
        $xref = $this->xref[$id];
        if ($parent === self::ROOT && $xref !== null && ($this->xrefIndex[$xref] ?? null) === $id) {
            unset($this->xrefIndex[$xref]);
        }
        $this->markRemoved($id);
    }

    /**
     * @internal
     *
     * @return list<int> child ids in order, continuation lines and unparsable lines included
     */
    public function childIds(int $id): array
    {
        $ids = [];
        for ($child = $this->firstChild[$id]; $child >= 0; $child = $this->next[$child]) {
            $ids[] = $child;
        }

        return $ids;
    }

    /** @internal */
    public function setPayload(int $id, ?string $payload): void
    {
        $this->assertAttached($id);
        $this->payload[$id] = $payload;
        $this->dirty[$id] = true;
    }

    /** @internal */
    public function setLineEnding(string $lineEnding): void
    {
        $this->lineEnding = $lineEnding;
    }

    /** @internal */
    public function setVersion(Version $version): void
    {
        $this->version = $version;
    }

    /** @internal */
    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /** @internal */
    public function assertAttached(int $id): void
    {
        if (isset($this->removed[$id])) {
            throw new DetachedNodeException("Node {$id} was removed from the document");
        }
    }

    /** @internal */
    public function parentOf(int $id): int
    {
        return $this->parent[$id];
    }

    /** @internal */
    public function depthOf(int $id): int
    {
        return $this->depth[$id];
    }

    /** @internal */
    public function xrefOf(int $id): ?string
    {
        return $this->xref[$id];
    }

    /** @internal */
    public function tagOf(int $id): string
    {
        return $this->tag[$id];
    }

    /** @internal */
    public function payloadOf(int $id): ?string
    {
        return $this->payload[$id];
    }

    /** @internal */
    public function lineOf(int $id): int
    {
        return $this->line[$id];
    }

    /** @internal */
    public function isRemoved(int $id): bool
    {
        return isset($this->removed[$id]);
    }

    /** @internal */
    public function isDirty(int $id): bool
    {
        return isset($this->dirty[$id]);
    }

    /** @internal */
    public function isInvalid(int $id): bool
    {
        return isset($this->invalid[$id]);
    }

    /**
     * @internal
     *
     * @return ?array{string, string} original line content and terminator; null for new or modified nodes
     */
    public function originalBytes(int $id): ?array
    {
        if ($this->offset[$id] < 0 || isset($this->dirty[$id])) {
            return null;
        }

        return [
            substr($this->source, $this->offset[$id], $this->contentLength[$id]),
            substr($this->source, $this->offset[$id] + $this->contentLength[$id], $this->eolLength[$id]),
        ];
    }

    /** @internal original terminator of the line, '' if new or the line had none */
    public function originalEol(int $id): string
    {
        return $this->offset[$id] < 0 ? '' : substr($this->source, $this->offset[$id] + $this->contentLength[$id], $this->eolLength[$id]);
    }

    /**
     * @internal
     *
     * @return \Generator<int, int> attached node ids in document order (depth first), root excluded
     */
    public function walk(): \Generator
    {
        $stack = array_reverse($this->childIds(self::ROOT));
        while ($stack !== []) {
            $id = array_pop($stack);
            yield $id;
            for ($child = $this->lastChild[$id]; $child >= 0; $child = $this->prev[$child]) {
                $stack[] = $child;
            }
        }
    }

    private function firstRecord(string $tag): ?Node
    {
        for ($id = $this->firstChild[self::ROOT]; $id >= 0; $id = $this->next[$id]) {
            if ($this->tag[$id] === $tag) {
                return $this->node($id);
            }
        }

        return null;
    }

    private function markRemoved(int $id): void
    {
        $this->removed[$id] = true;
        for ($child = $this->firstChild[$id]; $child >= 0; $child = $this->next[$child]) {
            $this->markRemoved($child);
        }
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\DetachedNodeException;
use Korin\Gedcom\Exception\InvalidPathException;
use Korin\Gedcom\Io\Codec\Codec;
use Korin\Gedcom\Io\Codec\Codecs;
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

    /** Bits of {@see $pos} below the line offset: value start (21 bits), terminator length (3 bits). */
    private const POS_SHIFT = 24;

    /** Value start meaning "the payload is stored in {@see $payload}". */
    private const EXPLICIT = 0x1FFFFF;

    private const LOW32 = 0xFFFFFFFF;

    // One packed array per field, sized by the number of lines; everything per line that can be
    // derived (depth, line number, content length, the value of an unmodified line) is not stored.

    /** @var array<int, int> */
    private array $parent = [self::ROOT => -1];

    /** @var array<int, string> empty for lines that could not be parsed; interned by the parser */
    private array $tag = [self::ROOT => ''];

    /** @var array<int, int> siblings: (prev + 1) | (next + 1) << 32 */
    private array $links = [self::ROOT => 0];

    /**
     * @var array<int, int> -1 for new nodes; otherwise offset << 24 | value start << 3 | terminator length.
     *                      The value start is a byte position in the decoded line, 0 when there is no value.
     */
    private array $pos = [self::ROOT => -1];

    /** @var array<int, int> only nodes with children: (first + 1) | (last + 1) << 32 */
    private array $children = [];

    /** @var array<int, string> only nodes with an xref */
    private array $xref = [];

    /** @var array<int, ?string> line value (escaped, UTF-8) of new and modified nodes only */
    private array $payload = [];

    /** Parsed lines are nodes 1..$parsed, contiguous in {@see $source}. */
    private int $parsed = 0;

    private ?Codec $codec = null;

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
        $this->markDirty($id);
        $this->xref[$id] = $to;
        unset($this->xrefIndex[$from]);
        $this->xrefIndex[$to] = $id;
        // Unmodified lines are only decoded when the source contains the pointer at all.
        $candidates = str_contains($this->source, $this->encoding->ascii($from)) ? array_keys($this->parent) : array_keys($this->payload);
        foreach ($candidates as $node) {
            if ($node !== self::ROOT && !isset($this->removed[$node]) && $this->payloadOf($node) === $from) {
                $this->setPayload($node, $to);
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
     * @internal a node that is not in the source: added by editing or by {@see self::create()}
     *
     * @param ?int $before sibling to insert before; null appends
     */
    public function createNode(int $parent, ?string $xref, string $tag, ?string $payload, ?int $before = null): int
    {
        $id = $this->append($parent, $xref, $tag, -1, $before);
        $this->payload[$id] = $payload;
        $this->dirty[$id] = true;

        return $id;
    }

    /**
     * @internal the next line of the source, appended as the last child of $parent
     *
     * @param int $valueStart byte position of the value in the decoded line; 0 when there is none
     */
    public function addParsedLine(int $parent, ?string $xref, string $tag, ?string $value, int $valueStart, int $offset, int $eolLength, bool $invalid = false): int
    {
        $explicit = $valueStart >= self::EXPLICIT;
        $id = $this->append($parent, $xref, $tag, $offset << self::POS_SHIFT | ($explicit ? self::EXPLICIT : $valueStart) << 3 | $eolLength, null);
        if ($explicit) {
            $this->payload[$id] = $value;
        }
        if ($invalid) {
            $this->invalid[$id] = true;
        }
        $this->parsed = $id;

        return $id;
    }

    /** @internal */
    public function removeNode(int $id): void
    {
        $this->assertAttached($id);
        $parent = $this->parent[$id];
        $prev = $this->prevOf($id);
        $next = $this->nextOf($id);
        if ($prev >= 0) {
            $this->setLinks($prev, $this->prevOf($prev), $next);
        } else {
            $this->setChildren($parent, $next, $this->lastChildOf($parent));
        }
        if ($next >= 0) {
            $this->setLinks($next, $prev, $this->nextOf($next));
        } else {
            $this->setChildren($parent, $this->firstChildOf($parent), $prev);
        }
        $xref = $this->xref[$id] ?? null;
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
        for ($child = $this->firstChildOf($id); $child >= 0; $child = ($this->links[$child] >> 32) - 1) {
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

    /** @internal 0 for records */
    public function depthOf(int $id): int
    {
        $depth = -1;
        for ($node = $this->parent[$id]; $node >= 0; $node = $this->parent[$node]) {
            ++$depth;
        }

        return $depth;
    }

    /** @internal */
    public function xrefOf(int $id): ?string
    {
        return $this->xref[$id] ?? null;
    }

    /** @internal */
    public function tagOf(int $id): string
    {
        return $this->tag[$id];
    }

    /** @internal line value as in the file (escaped), decoded to UTF-8 */
    public function payloadOf(int $id): ?string
    {
        $pos = $this->pos[$id];
        $valueStart = $pos >> 3 & self::EXPLICIT;
        if ($pos < 0 || $valueStart === self::EXPLICIT || isset($this->dirty[$id])) {
            return $this->payload[$id] ?? null;
        }
        if ($valueStart === 0) {
            return null;
        }
        $raw = substr($this->source, $pos >> self::POS_SHIFT, $this->contentLength($id));
        $this->codec ??= Codecs::for($this->encoding);

        return substr($this->codec->decode($raw) ?? $this->codec->decodeLossy($raw), $valueStart);
    }

    /** @internal 1-based source line, 0 for nodes added after reading */
    public function lineOf(int $id): int
    {
        return $this->pos[$id] < 0 ? 0 : $id;
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
        $pos = $this->pos[$id];
        if ($pos < 0 || isset($this->dirty[$id])) {
            return null;
        }
        $offset = $pos >> self::POS_SHIFT;
        $length = $this->contentLength($id);

        return [substr($this->source, $offset, $length), substr($this->source, $offset + $length, $pos & 7)];
    }

    /** @internal original terminator of the line, '' if new or the line had none */
    public function originalEol(int $id): string
    {
        $pos = $this->pos[$id];

        return $pos < 0 ? '' : substr($this->source, ($pos >> self::POS_SHIFT) + $this->contentLength($id), $pos & 7);
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
            for ($child = $this->lastChildOf($id); $child >= 0; $child = ($this->links[$child] & self::LOW32) - 1) {
                $stack[] = $child;
            }
        }
    }

    private function append(int $parent, ?string $xref, string $tag, int $pos, ?int $before): int
    {
        $id = count($this->parent);
        $this->parent[$id] = $parent;
        $this->tag[$id] = $tag;
        $this->pos[$id] = $pos;
        if ($xref !== null) {
            $this->xref[$id] = $xref;
            if ($parent === self::ROOT) {
                $this->xrefIndex[$xref] ??= $id;
            }
        }
        $first = $this->firstChildOf($parent);
        $last = $this->lastChildOf($parent);
        if ($before === null) {
            $this->links[$id] = $last + 1;
            if ($last >= 0) {
                $this->setLinks($last, $this->prevOf($last), $id);
            } else {
                $first = $id;
            }
            $this->setChildren($parent, $first, $id);
        } else {
            $prev = $this->prevOf($before);
            $this->setLinks($id, $prev, $before);
            $this->setLinks($before, $id, $this->nextOf($before));
            if ($prev >= 0) {
                $this->setLinks($prev, $this->prevOf($prev), $id);
            } else {
                $this->setChildren($parent, $id, $last);
            }
        }

        return $id;
    }

    /** Keeps the current value of an unmodified line when the line is about to be rewritten. */
    private function markDirty(int $id): void
    {
        if (!isset($this->dirty[$id])) {
            $this->payload[$id] = $this->payloadOf($id);
            $this->dirty[$id] = true;
        }
    }

    /** Content bytes of a parsed line: up to the next line, which starts right after its terminator. */
    private function contentLength(int $id): int
    {
        $pos = $this->pos[$id];
        $end = $id < $this->parsed ? $this->pos[$id + 1] >> self::POS_SHIFT : strlen($this->source);

        return $end - ($pos >> self::POS_SHIFT) - ($pos & 7);
    }

    private function prevOf(int $id): int
    {
        return ($this->links[$id] & self::LOW32) - 1;
    }

    private function nextOf(int $id): int
    {
        return ($this->links[$id] >> 32) - 1;
    }

    private function setLinks(int $id, int $prev, int $next): void
    {
        $this->links[$id] = ($prev + 1) | ($next + 1) << 32;
    }

    private function firstChildOf(int $id): int
    {
        return isset($this->children[$id]) ? ($this->children[$id] & self::LOW32) - 1 : -1;
    }

    private function lastChildOf(int $id): int
    {
        return isset($this->children[$id]) ? ($this->children[$id] >> 32) - 1 : -1;
    }

    private function setChildren(int $id, int $first, int $last): void
    {
        if ($first < 0) {
            unset($this->children[$id]);
        } else {
            $this->children[$id] = ($first + 1) | ($last + 1) << 32;
        }
    }

    private function firstRecord(string $tag): ?Node
    {
        for ($id = $this->firstChildOf(self::ROOT); $id >= 0; $id = $this->nextOf($id)) {
            if ($this->tag[$id] === $tag) {
                return $this->node($id);
            }
        }

        return null;
    }

    private function markRemoved(int $id): void
    {
        $this->removed[$id] = true;
        foreach ($this->childIds($id) as $child) {
            $this->markRemoved($child);
        }
    }
}

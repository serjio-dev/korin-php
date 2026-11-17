<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\DetachedNodeException;
use Korin\Gedcom\Exception\InvalidPathException;
use Korin\Gedcom\Exception\ReadOnlyException;
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

    /** @var array<string, int> xref => first record with it; see {@see self::index()} */
    private array $xrefIndex = [];

    private bool $readOnly = false;

    /** @var array<int, int> first node => line base, for records of a file parsed together that are not contiguous in it */
    private array $lineBases = [];

    /** Whether {@see $xrefIndex} must be rebuilt: setXref() can leave duplicates it cannot track. */
    private bool $indexStale = false;

    /**
     * @internal use {@see \Korin\Gedcom\Gedcom::parse()} or {@see self::create()}
     *
     * @param list<Diagnostic> $diagnostics
     * @param int              $lineBase    lines of the file before the source (a record of a stream)
     */
    public function __construct(
        private Version $version,
        private readonly Encoding $encoding,
        private readonly bool $bom,
        private string $lineEnding,
        private readonly string $source = '',
        private array $diagnostics = [],
        private readonly int $lineBase = 0,
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
        $id = $this->index()[$xref] ?? null;

        return $id !== null ? $this->node($id) : null;
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
        $this->assertWritable();
        if ($xref !== null && isset($this->index()[$xref])) {
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
        $this->assertWritable();
        $this->record($xref)?->remove();
    }

    /** Renames a record's xref and every pointer to it. */
    public function renameXref(string $from, string $to): void
    {
        $this->assertWritable();
        if (!isset($this->index()[$from])) {
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

    /** Cheap independent snapshot: arrays are copied on write. A copy of a read-only document can be edited. */
    public function copy(): self
    {
        $copy = clone $this;
        $copy->readOnly = false;

        return $copy;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /** @internal from now on every change throws {@see ReadOnlyException} */
    public function freeze(): void
    {
        $this->readOnly = true;
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
    /** @internal the id the next node created gets: ids only grow, so a node with a lower id was there before */
    public function nextId(): int
    {
        return count($this->parent);
    }

    public function createNode(int $parent, ?string $xref, string $tag, ?string $payload, ?int $before = null): int
    {
        $this->assertWritable();
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
        $this->assertWritable();
        $this->assertAttached($id);
        $parent = $this->parent[$id];
        $this->unlink($id);
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
        $this->assertWritable();
        $this->assertAttached($id);
        $this->payload[$id] = $payload;
        $this->dirty[$id] = true;
    }

    /**
     * @internal moves a substructure with its own substructures under another structure,
     * before $before or last, or a record among the records (5.5.5 SUBM after HEAD; parent 0)
     * (conversion); ids are kept, the moved lines are written anew
     */
    public function moveNode(int $id, int $parent, ?int $before = null): void
    {
        $this->assertWritable();
        $this->assertAttached($id);
        $this->assertAttached($parent);
        if (($this->parent[$id] === self::ROOT) !== ($parent === self::ROOT)) {
            throw new \InvalidArgumentException('Records stay records and substructures substructures');
        }
        for ($node = $parent; $node > 0; $node = $this->parent[$node]) {
            if ($node === $id) {
                throw new \InvalidArgumentException('A structure cannot move under itself');
            }
        }
        $this->unlink($id);
        $this->parent[$id] = $parent;
        $this->link($id, $parent, $before);
        $stack = [$id];
        while ($stack !== []) {
            $node = array_pop($stack);
            $this->markDirty($node);
            array_push($stack, ...$this->childIds($node));
        }
    }

    /** @internal a new tag for a node, its value and substructures kept (conversion) */
    public function setTag(int $id, string $tag): void
    {
        $this->assertWritable();
        $this->assertAttached($id);
        $this->markDirty($id);
        $this->tag[$id] = $tag;
    }

    /**
     * @internal a new xref for a record; unlike {@see self::renameXref()}, pointers to it are
     * left as they are, and the xref may be one another record has (a duplicate stays one)
     */
    public function setXref(int $id, ?string $xref): void
    {
        $this->assertWritable();
        $this->assertAttached($id);
        $this->markDirty($id);
        if ($xref === null) {
            unset($this->xref[$id]);
        } else {
            $this->xref[$id] = $xref;
        }
        $this->indexStale = true;
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
    public function assertWritable(): void
    {
        if ($this->readOnly) {
            throw new ReadOnlyException('The document is read only; edit a copy');
        }
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

    /**
     * @internal a cheap filter before {@see self::payloadOf()}: false only when the line
     * certainly has no such ASCII character in its value. For an unmodified line it looks at
     * the source bytes after the xref, without decoding (in UTF-16 too: an ASCII character's
     * code unit contains its byte).
     */
    public function mayContain(int $id, string $ascii): bool
    {
        $pos = $this->pos[$id];
        if ($pos < 0 || isset($this->dirty[$id])) {
            return str_contains($this->payload[$id] ?? '', $ascii);
        }
        $offset = $pos >> self::POS_SHIFT;
        $length = $this->contentLength($id);
        if (isset($this->xref[$id]) && $ascii === '@') {
            // Past the xref: from the byte after the second "@" (in UTF-16 maybe earlier, never later).
            $skip = strcspn($this->source, '@', $offset, $length);
            $skip += 1 + strcspn($this->source, '@', $offset + $skip + 1, max(0, $length - $skip - 1)) + 1;
            $offset += min($skip, $length);
            $length -= min($skip, $length);
        }

        return $length > 0 && strcspn($this->source, $ascii, $offset, $length) < $length;
    }

    /**
     * @internal the record after an attached one, or the first record after 0; null at the end.
     * Unlike {@see self::records()}, constant time.
     */
    public function nextRecord(int $after): ?int
    {
        $id = $after === self::ROOT ? $this->firstChildOf(self::ROOT) : $this->nextOf($after);

        return $id >= 0 ? $id : null;
    }

    /** @internal */
    public function hasChildren(int $id): bool
    {
        return isset($this->children[$id]);
    }

    /** @internal 1-based source line, 0 for nodes added after reading */
    public function lineOf(int $id): int
    {
        if ($this->pos[$id] < 0) {
            return 0;
        }
        $base = $this->lineBase;
        foreach ($this->lineBases as $first => $lineBase) {
            if ($id < $first) {
                break;
            }
            $base = $lineBase;
        }

        return $id + $base;
    }

    /** @internal the lines parsed from now on start at this line of the file (a record parsed with others it does not follow) */
    public function continueAtLine(int $line): void
    {
        $this->lineBases[$this->nextId()] = $line - $this->nextId();
    }

    /** @internal lines of the file before the source: 0 unless the document is a record of a stream */
    public function lineBase(): int
    {
        return $this->lineBase;
    }

    /** @internal whether a node with this id exists and is attached */
    public function contains(int $id): bool
    {
        return isset($this->parent[$id]) && !isset($this->removed[$id]);
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

    /**
     * @internal the original line decoded to UTF-8, for writing it in another encoding
     *
     * @return ?array{string, string} line content and terminator; null for new or modified nodes
     */
    public function originalText(int $id): ?array
    {
        $original = $this->originalBytes($id);
        if ($original === null) {
            return null;
        }
        $this->codec ??= Codecs::for($this->encoding);

        return [$this->codec->decode($original[0]) ?? $this->codec->decodeLossy($original[0]), (string) $this->codec->decode($original[1])];
    }

    /**
     * @internal the original line with the byte position of its value in the decoded line
     *
     * @return ?array{string, string, int} content, terminator, value start (0: no value); null
     *                                      for new or modified nodes and very long values
     */
    public function originalLine(int $id): ?array
    {
        $pos = $this->pos[$id];
        $valueStart = $pos >> 3 & self::EXPLICIT;
        if ($pos < 0 || $valueStart === self::EXPLICIT || isset($this->dirty[$id])) {
            return null;
        }
        $offset = $pos >> self::POS_SHIFT;
        $length = $this->contentLength($id);

        return [substr($this->source, $offset, $length), substr($this->source, $offset + $length, $pos & 7), $valueStart];
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
     * @param ?list<int> $records only these records (convertFile() writes some of a document's records apart)
     *
     * @return \Generator<int, int> attached node ids in document order (depth first), root excluded
     */
    public function walk(?array $records = null): \Generator
    {
        $stack = array_reverse($records ?? $this->childIds(self::ROOT));
        while ($stack !== []) {
            $id = array_pop($stack);
            yield $id;
            for ($child = $this->lastChildOf($id); $child >= 0; $child = ($this->links[$child] & self::LOW32) - 1) {
                $stack[] = $child;
            }
        }
    }

    /** @return array<string, int> xref => the first record with it, in document order */
    private function index(): array
    {
        if ($this->indexStale) {
            $this->indexStale = false;
            $this->xrefIndex = [];
            foreach ($this->childIds(self::ROOT) as $id) {
                if (isset($this->xref[$id])) {
                    $this->xrefIndex[$this->xref[$id]] ??= $id;
                }
            }
        }

        return $this->xrefIndex;
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
        $this->link($id, $parent, $before);

        return $id;
    }

    /** Inserts a node that is in no list into the children of $parent, before $before or last. */
    private function link(int $id, int $parent, ?int $before): void
    {
        $first = $this->firstChildOf($parent);
        $last = $this->lastChildOf($parent);
        if ($before === null) {
            $this->setLinks($id, $last, -1);
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
    }

    /** Takes a node out of its parent's children; its own children stay with it. */
    private function unlink(int $id): void
    {
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

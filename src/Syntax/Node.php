<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Version;

/**
 * Live view of one line and its substructures. CONC/CONT lines are not children here:
 * they are joined into {@see value()}. Any access after the node was removed throws
 * {@see \Korin\Gedcom\Exception\DetachedNodeException}.
 */
final readonly class Node
{
    private const CONTINUATION = ['CONC' => true, 'CONT' => true];

    /** @internal use {@see Document} methods to obtain nodes */
    public function __construct(
        private Document $document,
        private int $id,
    ) {}

    /** @internal stable within its document */
    public function id(): int
    {
        return $this->id;
    }

    public function tag(): string
    {
        $this->document->assertAttached($this->id);

        return $this->document->tagOf($this->id);
    }

    public function xref(): ?string
    {
        $this->document->assertAttached($this->id);

        return $this->document->xrefOf($this->id);
    }

    /** Text value with CONC/CONT joined (CONT as "\n") and "@" escaping removed; null for pointers and empty lines. */
    public function value(): ?string
    {
        return $this->valueFor($this->document->version());
    }

    /** @internal value() by the rules of another version than the document's (conversion) */
    public function valueFor(Version $version): ?string
    {
        $this->document->assertAttached($this->id);
        $payload = $this->document->payloadOf($this->id);
        if ($payload !== null && Escaping::isPointer($payload)) {
            return null;
        }
        $value = $payload === null ? null : Escaping::unescape($payload, $version);
        foreach ($this->document->childIds($this->id) as $child) {
            $tag = $this->document->tagOf($child);
            if (!isset(self::CONTINUATION[$tag])) {
                continue;
            }
            $part = $this->document->payloadOf($child);
            $value = ($value ?? '') . ($tag === 'CONT' ? "\n" : '') . ($part === null ? '' : Escaping::unescape($part, $version));
        }

        return $value;
    }

    /** Pointer target such as "@I1@" or "@VOID@"; null when the value is not a pointer. */
    public function pointer(): ?string
    {
        $this->document->assertAttached($this->id);
        $payload = $this->document->payloadOf($this->id);

        return $payload !== null && Escaping::isPointer($payload) ? $payload : null;
    }

    /** 0 for records. */
    public function level(): int
    {
        return $this->document->depthOf($this->id);
    }

    /** 1-based line in the source; null for nodes added after reading. */
    public function line(): ?int
    {
        $line = $this->document->lineOf($this->id);

        return $line > 0 ? $line : null;
    }

    public function parent(): ?Node
    {
        $this->document->assertAttached($this->id);
        $parent = $this->document->parentOf($this->id);

        return $parent > 0 ? $this->document->node($parent) : null;
    }

    public function document(): Document
    {
        return $this->document;
    }

    /** @return list<Node> substructures, without CONC/CONT and unparsable lines */
    public function children(): array
    {
        $this->document->assertAttached($this->id);
        $children = [];
        foreach ($this->document->childIds($this->id) as $child) {
            if (!isset(self::CONTINUATION[$this->document->tagOf($child)]) && !$this->document->isInvalid($child)) {
                $children[] = $this->document->node($child);
            }
        }

        return $children;
    }

    /** @return list<Node> */
    public function all(string $tag): array
    {
        return array_values(array_filter($this->children(), static fn(Node $child): bool => $child->tag() === $tag));
    }

    public function first(string $tag): ?Node
    {
        return $this->all($tag)[0] ?? null;
    }

    /** Path in the corpus grammar, e.g. "@I1@/NAME[1]/SURN"; the index appears only when the tag repeats. */
    public function path(): string
    {
        $parent = $this->parent();
        if ($parent === null) {
            return $this->xref() ?? $this->tag();
        }
        $siblings = $parent->all($this->tag());
        $segment = $this->tag();
        if (count($siblings) > 1) {
            foreach ($siblings as $index => $sibling) {
                if ($sibling->id === $this->id) {
                    $segment .= "[{$index}]";
                }
            }
        }

        return $parent->path() . '/' . $segment;
    }

    public function isRemoved(): bool
    {
        return $this->document->isRemoved($this->id);
    }

    /** Replaces the text value; "\n" becomes CONT lines, and old CONC/CONT lines are dropped. */
    public function setValue(?string $value): self
    {
        return $this->setValueFor($value, $this->document->version());
    }

    /** @internal setValue() by the rules of another version than the document's (conversion) */
    public function setValueFor(?string $value, Version $version): self
    {
        $this->document->assertAttached($this->id);
        foreach ($this->document->childIds($this->id) as $child) {
            if (isset(self::CONTINUATION[$this->document->tagOf($child)])) {
                $this->document->removeNode($child);
            }
        }
        if ($value === null) {
            $this->document->setPayload($this->id, null);

            return $this;
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $value));
        $this->document->setPayload($this->id, Escaping::escape(array_shift($lines), $version));
        $before = $this->document->childIds($this->id)[0] ?? null;
        foreach ($lines as $line) {
            $this->document->createNode($this->id, null, 'CONT', $line === '' ? null : Escaping::escape($line, $version), $before);
        }

        return $this;
    }

    public function setPointer(string $xref): self
    {
        if (!Escaping::isPointer($xref)) {
            throw new \InvalidArgumentException("Not a pointer: {$xref}");
        }
        $this->setValue(null);
        $this->document->setPayload($this->id, $xref);

        return $this;
    }

    /**
     * @param ?int $position 0-based index among {@see children()}; null appends
     */
    public function addChild(string $tag, ?string $value = null, ?int $position = null): Node
    {
        $children = $this->children();
        $before = $position === null ? null : ($children[$position] ?? null)?->id;
        $node = $this->document->node($this->document->createNode($this->id, null, $tag, null, $before));
        if ($value !== null) {
            Escaping::isPointer($value) ? $node->setPointer($value) : $node->setValue($value);
        }

        return $node;
    }

    public function remove(): void
    {
        $this->document->removeNode($this->id);
    }
}

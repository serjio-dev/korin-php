<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Records;

use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * The records of a document; a key is the record's node id.
 *
 * @internal
 */
final class DocumentRecords implements Records
{
    /** @var ?array<string, int> xref => the first record with it, in document order */
    private ?array $xrefs = null;

    public function __construct(private readonly Document $document) {}

    public function version(): Version
    {
        return $this->document->version();
    }

    public function hasBom(): bool
    {
        return $this->document->hasBom();
    }

    public function all(): iterable
    {
        foreach ($this->document->childIds(0) as $id) {
            yield $id => [$this->document, $id];
        }
    }

    public function diagnostics(): array
    {
        return $this->document->diagnostics();
    }

    public function find(string $xref): ?int
    {
        if ($this->xrefs === null) {
            $this->xrefs = [];
            foreach ($this->document->childIds(0) as $id) {
                $found = $this->document->xrefOf($id);
                if ($found !== null && !$this->document->isInvalid($id)) {
                    $this->xrefs[$found] ??= $id;
                }
            }
        }

        return $this->xrefs[$xref] ?? null;
    }

    public function tag(int $key): string
    {
        return $this->document->tagOf($key);
    }

    public function line(int $key): int
    {
        return $this->document->lineOf($key);
    }

    public function get(int $key): array
    {
        return [$this->document, $key];
    }
}

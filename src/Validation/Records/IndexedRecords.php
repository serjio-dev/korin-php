<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Records;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Version;

/**
 * The records of an indexed file; a key is the record's number. Going through them reads the
 * file once; a record looked up is read from the file unless it is among the cached ones.
 *
 * @internal
 */
final class IndexedRecords implements Records
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(private readonly IndexedDocument $document) {}

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
        $this->diagnostics = [];
        foreach ($this->document->records() as $i => $node) {
            array_push($this->diagnostics, ...$node->document()->diagnostics());
            yield $i => [$node->document(), $node->id()];
        }
        // After the lines, as read() reports them.
        array_push($this->diagnostics, ...$this->document->diagnostics());
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function find(string $xref): ?int
    {
        return $this->document->xrefs()[$xref] ?? null;
    }

    public function tag(int $key): string
    {
        return $this->document->tagAt($key);
    }

    public function line(int $key): int
    {
        return $this->document->lineAt($key);
    }

    public function get(int $key): array
    {
        $node = $this->document->recordAt($key);

        return [$node->document(), $node->id()];
    }
}

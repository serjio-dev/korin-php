<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Records;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * The records a validation goes through, one after another, and those it looks up by xref: of
 * a document, or of an indexed file, where each record is a document of its own. A record is
 * known by a key that grows with its place in the file.
 *
 * @internal
 */
interface Records
{
    public function version(): Version;

    public function hasBom(): bool;

    /** @return iterable<int, array{Document, int}> key => the record's document and node, in order */
    public function all(): iterable;

    /** @return list<Diagnostic> the syntax diagnostics of reading; of an indexed file those of the records gone through */
    public function diagnostics(): array;

    /** Key of the first record with this xref (in order; lines that are not GEDCOM lines have none). */
    public function find(string $xref): ?int;

    public function tag(int $key): string;

    /** 1-based line of the record; 0 for one added after reading. */
    public function line(int $key): int;

    /** @return array{Document, int} the record's document and node */
    public function get(int $key): array;
}

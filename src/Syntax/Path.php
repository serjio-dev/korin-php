<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Exception\InvalidPathException;

/**
 * Node path, e.g. "HEAD/GEDC/VERS" or "@I1@/NAME[0]/SURN". The first segment is HEAD,
 * TRLR or a record xref; each next one is a tag with an optional 0-based index among
 * siblings with that tag. Without an index the tag must be unique among its siblings.
 */
final readonly class Path
{
    /**
     * @param list<array{string, ?int}> $segments tag and optional index below the record
     */
    private function __construct(
        public string $record,
        public array $segments,
    ) {}

    public static function parse(string $path): self
    {
        $parts = explode('/', $path);
        $record = array_shift($parts);
        if (preg_match('/^(HEAD|TRLR|@[^@\/]+@)$/', $record) !== 1) {
            throw new InvalidPathException("Path must start with HEAD, TRLR or an xref: {$path}");
        }
        $segments = [];
        foreach ($parts as $part) {
            if (preg_match('/^(_?[A-Za-z0-9_]+)(?:\[(\d+)\])?$/', $part, $m) !== 1) {
                throw new InvalidPathException("Invalid path segment '{$part}' in {$path}");
            }
            $segments[] = [$m[1], isset($m[2]) ? (int) $m[2] : null];
        }

        return new self($record, $segments);
    }
}

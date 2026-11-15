<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Severity;

/**
 * One entry of a loss report: data the target version cannot hold as it was. The path and the
 * line are those of the source document. Messages never quote more than a short fragment.
 */
final readonly class Loss implements \JsonSerializable, \Stringable
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $path,
        public ?int $line,
        public StoredAs $storedAs,
        public string $message,
    ) {}

    /** @return array{code: string, severity: string, path: string, line?: int, storedAs: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity->value, 'path' => $this->path]
            + ($this->line === null ? [] : ['line' => $this->line])
            + ['storedAs' => $this->storedAs->value, 'message' => $this->message];
    }

    public function __toString(): string
    {
        $where = ($this->line !== null ? " line {$this->line}" : '') . " ({$this->path})";

        return "{$this->code} {$this->severity->value}{$where}, {$this->storedAs->value}: {$this->message}";
    }
}

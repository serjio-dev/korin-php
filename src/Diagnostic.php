<?php

declare(strict_types=1);

namespace Korin\Gedcom;

/**
 * A problem found while reading or validating. Codes are stable within a major version
 * and documented in docs/diagnostics.md. Messages never quote more than a short fragment
 * of the file: trees hold personal data and diagnostics end up in logs.
 */
final readonly class Diagnostic implements \Stringable
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
        public ?int $line = null,
        public ?string $path = null,
    ) {}

    public function __toString(): string
    {
        $where = $this->line !== null ? " line {$this->line}" : '';
        $where .= $this->path !== null ? " ({$this->path})" : '';

        return "{$this->code} {$this->severity->value}{$where}: {$this->message}";
    }
}

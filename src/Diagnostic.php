<?php

declare(strict_types=1);

namespace Korin\Gedcom;

/**
 * A problem found while reading or validating. Codes are stable within a major version
 * and documented in docs/diagnostics.md. Messages never quote more than a short fragment
 * of the file: trees hold personal data and diagnostics end up in logs.
 */
final readonly class Diagnostic implements \Stringable, \JsonSerializable
{
    /**
     * @param ?string $path tree path in the corpus grammar, e.g. "@I1@/BIRT/DATE"
     * @param ?string $reference the specification term or section the rule comes from
     */
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
        public ?int $line = null,
        public ?string $path = null,
        public ?string $reference = null,
    ) {}

    public function withSeverity(Severity $severity): self
    {
        return new self($this->code, $severity, $this->message, $this->line, $this->path, $this->reference);
    }

    /** @return array{code: string, severity: string, message: string, line: ?int, path: ?string, reference: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'line' => $this->line,
            'path' => $this->path,
            'reference' => $this->reference,
        ];
    }

    public function __toString(): string
    {
        $where = $this->line !== null ? " line {$this->line}" : '';
        $where .= $this->path !== null ? " ({$this->path})" : '';

        return "{$this->code} {$this->severity->value}{$where}: {$this->message}";
    }
}

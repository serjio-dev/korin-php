<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation;

use Korin\Gedcom\Severity;

/** Which diagnostics a validation reports and how severe they are. */
final readonly class ValidationOptions
{
    /**
     * @param array<string, true> $disabled codes left out of the report
     * @param array<string, Severity> $severities codes reported with another severity
     */
    public function __construct(
        public array $disabled = [],
        public array $severities = [],
    ) {}

    /** Leaves these codes (e.g. "KV2001") out of the report. */
    public function withDisabled(string ...$codes): self
    {
        return new self($this->disabled + array_fill_keys($codes, true), $this->severities);
    }

    /** Reports a code with another severity, e.g. a warning as an error. */
    public function withSeverity(string $code, Severity $severity): self
    {
        return new self($this->disabled, [$code => $severity] + $this->severities);
    }
}

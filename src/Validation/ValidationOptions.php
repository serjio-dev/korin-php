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
     * @param bool $plausibility whether the optional plausibility checks (KV5xxx) run
     */
    public function __construct(
        public array $disabled = [],
        public array $severities = [],
        public bool $plausibility = false,
    ) {}

    /** Leaves these codes (e.g. "KV2001") out of the report. */
    public function withDisabled(string ...$codes): self
    {
        return new self($this->disabled + array_fill_keys($codes, true), $this->severities, $this->plausibility);
    }

    /** Reports a code with another severity, e.g. a warning as an error. */
    public function withSeverity(string $code, Severity $severity): self
    {
        return new self($this->disabled, [$code => $severity] + $this->severities, $this->plausibility);
    }

    /** Also runs the plausibility checks (KV5xxx): births after deaths, cycles in the pedigree and the like. */
    public function withPlausibility(bool $plausibility = true): self
    {
        return new self($this->disabled, $this->severities, $plausibility);
    }
}

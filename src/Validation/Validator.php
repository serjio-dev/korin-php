<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\PlausibilityRule;
use Korin\Gedcom\Validation\Rule\ReferenceRule;
use Korin\Gedcom\Validation\Rule\Rule;
use Korin\Gedcom\Validation\Rule\StructureRule;
use Korin\Gedcom\Validation\Rule\ValueRule;

/**
 * Checks a document against the profile of its version. The report starts with the syntax
 * diagnostics found while reading (KV1xxx); the document itself is never changed. The
 * plausibility checks (KV5xxx) run only when the options ask for them.
 */
final readonly class Validator
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [new StructureRule(), new ValueRule(), new ReferenceRule()];
    }

    public function validate(Document $document, ValidationOptions $options = new ValidationOptions()): ValidationReport
    {
        $diagnostics = $document->diagnostics();
        $profile = Profile::for($document->version());
        $rules = $options->plausibility ? [...$this->rules, new PlausibilityRule()] : $this->rules;
        foreach ($rules as $rule) {
            foreach ($rule->check($document, $profile) as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        $reported = [];
        foreach ($diagnostics as $diagnostic) {
            if (isset($options->disabled[$diagnostic->code])) {
                continue;
            }
            $severity = $options->severities[$diagnostic->code] ?? null;
            $reported[] = $severity === null ? $diagnostic : $diagnostic->withSeverity($severity);
        }
        // Document order; diagnostics without a line (about the whole file) come last.
        usort($reported, static fn(Diagnostic $a, Diagnostic $b): int => ($a->line ?? PHP_INT_MAX) <=> ($b->line ?? PHP_INT_MAX));

        return new ValidationReport($reported);
    }
}

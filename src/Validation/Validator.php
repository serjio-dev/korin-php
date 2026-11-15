<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\Rule;
use Korin\Gedcom\Validation\Rule\StructureRule;

/**
 * Checks a document against the profile of its version. The report starts with the syntax
 * diagnostics found while reading (KV1xxx); the document itself is never changed.
 */
final readonly class Validator
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [new StructureRule()];
    }

    public function validate(Document $document, ValidationOptions $options = new ValidationOptions()): ValidationReport
    {
        $diagnostics = $document->diagnostics();
        $version = $document->version();
        if (Profile::has($version)) {
            $profile = Profile::for($version);
            foreach ($this->rules as $rule) {
                foreach ($rule->check($document, $profile) as $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
            }
        } else {
            $diagnostics[] = new Diagnostic('KV2000', Severity::Info, "Structures are not checked: there is no profile for GEDCOM {$version->value} yet");
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

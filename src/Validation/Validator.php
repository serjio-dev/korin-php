<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Validation\Records\DocumentRecords;
use Korin\Gedcom\Validation\Records\IndexedRecords;
use Korin\Gedcom\Validation\Rule\FileRule;
use Korin\Gedcom\Validation\Rule\PlausibilityRule;
use Korin\Gedcom\Validation\Rule\ReferenceRule;
use Korin\Gedcom\Validation\Rule\Rule;
use Korin\Gedcom\Validation\Rule\StructureRule;
use Korin\Gedcom\Validation\Rule\ValueRule;

/**
 * Checks a document, or an indexed file, against the profile of its version: every rule sees
 * the records one at a time, in order. The report starts with the syntax
 * diagnostics found while reading (KV1xxx); the document itself is never changed. The
 * plausibility checks (KV5xxx) run only when the options ask for them.
 */
final readonly class Validator
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [new FileRule(), new StructureRule(), new ValueRule(), new ReferenceRule()];
    }

    /** An indexed file is read once, record by record; the records a check looks up are read from it again unless cached. */
    public function validate(Document|IndexedDocument $document, ValidationOptions $options = new ValidationOptions()): ValidationReport
    {
        $records = $document instanceof Document ? new DocumentRecords($document) : new IndexedRecords($document);
        $profile = Profile::for($records->version());
        $rules = $options->plausibility ? [...$this->rules, new PlausibilityRule()] : $this->rules;
        foreach ($rules as $rule) {
            $rule->begin($records, $profile);
        }
        foreach ($records->all() as $key => [$recordDocument, $id]) {
            foreach ($rules as $rule) {
                $rule->record($recordDocument, $id, $key);
            }
        }
        $diagnostics = $records->diagnostics();
        foreach ($rules as $rule) {
            array_push($diagnostics, ...$rule->end());
        }

        $reported = [];
        foreach ($diagnostics as $diagnostic) {
            if (isset($options->disabled[$diagnostic->code])) {
                continue;
            }
            $severity = $options->severities[$diagnostic->code] ?? null;
            $reported[] = $severity === null ? $diagnostic : $diagnostic->withSeverity($severity);
        }
        // Document order; diagnostics without a line (about the whole file) come last. On one line,
        // by code: read() and open() find them in another order (encoding before or after syntax).
        usort($reported, static fn(Diagnostic $a, Diagnostic $b): int => [$a->line ?? PHP_INT_MAX, $a->code, $a->path, $a->message]
            <=> [$b->line ?? PHP_INT_MAX, $b->code, $b->path, $b->message]);

        return new ValidationReport($reported);
    }
}

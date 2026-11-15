<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Version;

/**
 * Level 3: payloads of standard structures against the profile's payload types (KV3001–KV3010).
 * A payload is one of the alternatives of its structure
 * ("@<record>@|<SUBMITTER_TEXT>|<NULL>"); the types are version-specific (PayloadTypes).
 * Messages name the expected type but never quote a payload, except a short enumeration value.
 *
 * @internal
 */
final class ValueRule implements Rule
{
    private const CONTINUATION = ['CONC' => true, 'CONT' => true];
    private const NULL = '<NULL>';

    /** @var array<string, array{bool, bool, list<string>, ?string}> structure URI => payload alternatives, see shape() */
    private array $shapes = [];

    public function check(Document $document, Profile $profile): array
    {
        $this->shapes = [];
        $types = $profile->version() === Version::V7_0 ? new Payloads7($profile) : new Payloads5($profile);
        $out = [];
        foreach ($document->childIds(0) as $id) {
            $record = $profile->record($document->tagOf($id));
            if ($record !== null && !$document->isInvalid($id)) {
                $this->structure($document, $profile, $types, $id, $profile->structure($record->uri), $out);
            }
        }

        return $out;
    }

    /** @param list<Diagnostic> $out */
    private function structure(Document $document, Profile $profile, PayloadTypes $types, int $id, ?Structure $structure, array &$out): void
    {
        if ($structure === null) {
            return;
        }
        $substructures = 0;
        $continued = false;
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if (isset(self::CONTINUATION[$tag])) {
                $continued = true;
                continue;
            }
            if ($document->isInvalid($child)) {
                continue;
            }
            ++$substructures;
            $substructure = $structure->substructure($tag);
            if ($substructure !== null) {
                $this->structure($document, $profile, $types, $child, $profile->structure($substructure->uri), $out);
            }
        }
        $this->payload($document, $types, $id, $structure, $substructures, $continued, $out);
    }

    /** @param list<Diagnostic> $out */
    private function payload(Document $document, PayloadTypes $types, int $id, Structure $structure, int $substructures, bool $continued, array &$out): void
    {
        $raw = $document->payloadOf($id);
        $isPointer = $raw !== null && Escaping::isPointer($raw);
        // Only lines with CONC/CONT need the joined value; the rest is unescaped directly.
        $value = match (true) {
            $isPointer => $raw,
            $continued => (string) $document->node($id)->value(),
            default => $raw === null ? '' : Escaping::unescape($raw, $document->version()),
        };
        [$hasPayload, $nullable, $texts, $target] = $this->shapes[$structure->uri] ??= $this->shape($structure);
        $tag = $structure->tag;

        if ($value === '') {
            // TRLR is empty by definition; a missing required substructure is already KV2003.
            if ($types->requiresContent() && $substructures === 0 && $tag !== 'TRLR' && !$this->requiresSubstructures($structure)) {
                $out[] = $this->diagnostic($document, 'KV3007', "{$tag} has neither a value nor substructures", $id, $structure);
            } elseif ($hasPayload && !$nullable && array_filter($texts, $types->isOptional(...)) === []) {
                $out[] = $this->diagnostic($document, 'KV3001', "{$tag} requires a value", $id, $structure);
            }

            return;
        }
        if ($texts === [] && $target === null) {
            $out[] = $this->diagnostic($document, 'KV3002', "{$tag} must not have a value", $id, $structure);

            return;
        }
        if ($isPointer) {
            if ($target === null) {
                $out[] = $this->diagnostic($document, 'KV3004', "{$tag} must have a value, not a pointer", $id, $structure);
            }

            return;
        }
        if ($texts === []) {
            $out[] = $this->diagnostic($document, 'KV3003', "{$tag} must be a pointer to a record", $id, $structure);

            return;
        }
        // The value must be of one of the types; the problems of the first one are reported.
        $first = null;
        foreach ($texts as $type) {
            $problems = $types->problems($structure, $type, $value);
            if ($problems === []) {
                return;
            }
            $first ??= $problems;
        }
        foreach ($first as [$code, $severity, $message]) {
            $out[] = $this->diagnostic($document, $code, "{$tag} {$message}", $id, $structure, $severity);
        }
    }

    /**
     * The payload alternatives of a structure: whether it has a payload, whether "<NULL>" is
     * one of them, the types of non-pointer values, and the record type of a pointer.
     *
     * @return array{bool, bool, list<string>, ?string}
     */
    private function shape(Structure $structure): array
    {
        $alternatives = $structure->payload === null ? [] : explode('|', $structure->payload);
        $texts = array_values(array_filter($alternatives, static fn(string $type): bool => $type !== self::NULL && !str_starts_with($type, '@<')));

        return [$alternatives !== [], in_array(self::NULL, $alternatives, true), $texts, $structure->pointerTarget()];
    }

    private function requiresSubstructures(Structure $structure): bool
    {
        foreach ($structure->substructures as $substructure) {
            if ($substructure->cardinality->isRequired()) {
                return true;
            }
        }

        return false;
    }

    private function diagnostic(Document $document, string $code, string $message, int $id, Structure $structure, Severity $severity = Severity::Error): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, $severity, $message, $line > 0 ? $line : null, $document->node($id)->path(), $structure->uri);
    }
}

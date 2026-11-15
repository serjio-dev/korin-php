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
 * Level 3: payloads of standard structures against the profile's payload types (KV3001–KV3007).
 * GEDCOM 7.0 only for now; dates are not checked yet. Messages name the expected type but
 * never quote a payload, except a short enumeration value.
 *
 * @internal
 */
final class ValueRule implements Rule
{
    private const CONTINUATION = ['CONC' => true, 'CONT' => true];

    public function check(Document $document, ?Profile $profile): array
    {
        if ($profile === null || $profile->version() !== Version::V7_0) {
            return [];
        }
        $out = [];
        foreach ($document->childIds(0) as $id) {
            $record = $profile->record($document->tagOf($id));
            if ($record !== null && !$document->isInvalid($id)) {
                $this->structure($document, $profile, $id, $profile->structure($record->uri), $out);
            }
        }

        return $out;
    }

    /** @param list<Diagnostic> $out */
    private function structure(Document $document, Profile $profile, int $id, ?Structure $structure, array &$out): void
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
                $this->structure($document, $profile, $child, $profile->structure($substructure->uri), $out);
            }
        }
        $this->payload($document, $profile, $id, $structure, $substructures, $continued, $out);
    }

    /** @param list<Diagnostic> $out */
    private function payload(Document $document, Profile $profile, int $id, Structure $structure, int $substructures, bool $continued, array &$out): void
    {
        $raw = $document->payloadOf($id);
        $isPointer = $raw !== null && Escaping::isPointer($raw);
        // Only lines with CONC/CONT need the joined value; the rest is unescaped directly.
        $value = match (true) {
            $isPointer => $raw,
            $continued => (string) $document->node($id)->value(),
            default => $raw === null ? '' : Escaping::unescape($raw, $document->version()),
        };
        $type = $structure->payload;
        $tag = $structure->tag;

        if ($value === '') {
            // TRLR is empty by definition; a missing required substructure is already KV2003.
            if ($substructures === 0 && $tag !== 'TRLR' && !$this->requiresSubstructures($structure)) {
                $out[] = $this->diagnostic($document, 'KV3007', "{$tag} has neither a value nor substructures", $id, $structure);
            } elseif ($type !== null && !isset(PayloadGrammar::OPTIONAL[$type])) {
                $out[] = $this->diagnostic($document, 'KV3001', "{$tag} requires a value", $id, $structure);
            }

            return;
        }
        if ($type === null) {
            $out[] = $this->diagnostic($document, 'KV3002', "{$tag} must not have a value", $id, $structure);

            return;
        }
        if ($structure->pointerTarget() !== null) {
            if (!$isPointer) {
                $out[] = $this->diagnostic($document, 'KV3003', "{$tag} must be a pointer to a record", $id, $structure);
            }

            return;
        }
        if ($isPointer) {
            $out[] = $this->diagnostic($document, 'KV3004', "{$tag} must have a value, not a pointer", $id, $structure);

            return;
        }
        if ($type === PayloadGrammar::ENUM || $type === PayloadGrammar::LIST_ENUM) {
            $permitted = $profile->enumeration((string) $structure->enumerationSet);
            $items = $type === PayloadGrammar::ENUM ? [$value] : PayloadGrammar::listItems($value);
            foreach ($items as $item) {
                if (!PayloadGrammar::isEnumValue($item, $permitted)) {
                    $out[] = $this->diagnostic($document, 'KV3006', "{$tag} does not permit \"" . $this->fragment($item) . '"; expected one of ' . implode(', ', $permitted) . ' or an extension value', $id, $structure);
                }
            }

            return;
        }
        if (!PayloadGrammar::matches($type, $value)) {
            $out[] = $this->diagnostic($document, 'KV3005', "{$tag} must be " . (PayloadGrammar::NAMES[$type] ?? $type), $id, $structure);
        }
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

    /** At most 20 characters of a value: diagnostics end up in logs. */
    private function fragment(string $value): string
    {
        return preg_match('/^.{21}/su', $value) === 1 && preg_match('/^.{19}/su', $value, $m) === 1 ? $m[0] . '…' : $value;
    }

    private function diagnostic(Document $document, string $code, string $message, int $id, Structure $structure): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, Severity::Error, $message, $line > 0 ? $line : null, $document->node($id)->path(), $structure->uri);
    }
}

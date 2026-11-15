<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Spec\Substructure;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Version;

/**
 * Level 4: pointers and xrefs. Dangling pointers (KV4001) and duplicate xrefs (KV4002) are
 * checked in every version; the type of the record a pointer must point to (KV4003) needs the
 * profile. Family links must be mutual (KV4004): INDI.FAMC ↔ FAM.CHIL, INDI.FAMS ↔ FAM.HUSB/WIFE.
 * Xrefs are compared case-sensitively; in 7.0 "@VOID@" is a null pointer, not a dangling one.
 *
 * @internal
 */
final class ReferenceRule implements Rule
{
    private const VOID = '@VOID@';

    /** Links that must be mirrored: tag in INDI => tags in FAM that point back, and the reverse. */
    private const INDI_TO_FAM = ['FAMC' => ['CHIL'], 'FAMS' => ['HUSB', 'WIFE']];
    private const FAM_TO_INDI = ['CHIL' => 'FAMC', 'HUSB' => 'FAMS', 'WIFE' => 'FAMS'];

    public function check(Document $document, ?Profile $profile): iterable
    {
        /** @var array<string, int> $records xref => first record with it */
        $records = [];
        foreach ($document->childIds(0) as $id) {
            $xref = $document->xrefOf($id);
            if ($xref === null || $document->isInvalid($id)) {
                continue;
            }
            if (isset($records[$xref])) {
                yield $this->diagnostic($document, 'KV4002', Severity::Error, 'Xref is already used by the record on line ' . $document->lineOf($records[$xref]), $id);
                continue;
            }
            $records[$xref] = $id;
        }

        $void = $document->version() === Version::V7_0 ? self::VOID : null;
        foreach ($document->childIds(0) as $id) {
            yield from $this->pointers($document, $profile, $records, $void, $id, $this->definition($profile, $profile?->record($document->tagOf($id))));
        }
        yield from $this->familyLinks($document, $records);
    }

    /**
     * @param array<string, int> $records
     *
     * @return \Generator<int, Diagnostic>
     */
    private function pointers(Document $document, ?Profile $profile, array $records, ?string $void, int $id, ?Structure $structure): \Generator
    {
        $payload = $document->payloadOf($id);
        if ($payload !== null && $payload !== $void && Escaping::isPointer($payload) && !$document->isInvalid($id)) {
            $target = $records[$payload] ?? null;
            if ($target === null) {
                yield $this->diagnostic($document, 'KV4001', Severity::Error, 'Pointer to a record that does not exist', $id);
            } elseif ($profile !== null && $structure !== null) {
                $expected = $structure->pointerTarget();
                if ($expected !== null && $profile->record($document->tagOf($target))?->uri !== $expected) {
                    $want = $profile->structure($expected)->tag ?? $expected;
                    yield $this->diagnostic($document, 'KV4003', Severity::Error, "{$structure->tag} must point to a {$want} record, not {$document->tagOf($target)}", $id, $structure->uri);
                }
            }
        }
        foreach ($document->childIds($id) as $child) {
            yield from $this->pointers($document, $profile, $records, $void, $child, $this->definition($profile, $structure?->substructure($document->tagOf($child))));
        }
    }

    /**
     * @param array<string, int> $records
     *
     * @return \Generator<int, Diagnostic>
     */
    private function familyLinks(Document $document, array $records): \Generator
    {
        foreach ($records as $xref => $id) {
            $tag = $document->tagOf($id);
            $links = match ($tag) {
                'INDI' => self::INDI_TO_FAM,
                'FAM' => array_map(static fn(string $back): array => [$back], self::FAM_TO_INDI),
                default => [],
            };
            $otherType = $tag === 'INDI' ? 'FAM' : 'INDI';
            foreach ($document->childIds($id) as $child) {
                $back = $links[$document->tagOf($child)] ?? null;
                $target = $records[(string) $document->payloadOf($child)] ?? null;
                if ($back === null || $target === null || $document->tagOf($target) !== $otherType) {
                    continue;
                }
                if (!$this->pointsTo($document, $target, $back, $xref)) {
                    yield $this->diagnostic($document, 'KV4004', Severity::Warning, $document->payloadOf($child) . ' has no ' . implode(' or ', $back) . " pointing back to {$xref}", $child);
                }
            }
        }
    }

    /** @param list<string> $tags */
    private function pointsTo(Document $document, int $record, array $tags, string $xref): bool
    {
        foreach ($document->childIds($record) as $child) {
            if (in_array($document->tagOf($child), $tags, true) && $document->payloadOf($child) === $xref) {
                return true;
            }
        }

        return false;
    }

    private function definition(?Profile $profile, ?Substructure $substructure): ?Structure
    {
        return $substructure === null ? null : $profile?->structure($substructure->uri);
    }

    private function diagnostic(Document $document, string $code, Severity $severity, string $message, int $id, ?string $reference = null): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, $severity, $message, $line > 0 ? $line : null, $document->node($id)->path(), $reference);
    }
}

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
use Korin\Gedcom\Validation\Records\Records;
use Korin\Gedcom\Version;

/**
 * Level 4: pointers and xrefs: dangling pointers (KV4001), duplicate xrefs (KV4002), the type of
 * the record a pointer must point to (KV4003, from the profile). Family links must be mutual (KV4004): INDI.FAMC ↔ FAM.CHIL, INDI.FAMS ↔ FAM.HUSB/WIFE.
 * Xrefs are compared case-sensitively; in 7.0 "@VOID@" is a null pointer, not a dangling one.
 * A dangling pointer of an extension, or inside one, is a warning: what an extension points to
 * is not defined, as nothing else in it is ("_FAMC @VOID@", a 7.0 null pointer kept in 5.5.1).
 *
 * @internal
 */
final class ReferenceRule implements Rule
{
    private const VOID = '@VOID@';

    private const CHILD = 1;
    private const SPOUSE = 2;

    /** Links that must be mirrored, by tag: the record type they are in, kind, the tags pointing back. */
    private const LINKS = [
        'FAMC' => ['INDI', self::CHILD, ['CHIL']],
        'FAMS' => ['INDI', self::SPOUSE, ['HUSB', 'WIFE']],
        'CHIL' => ['FAM', self::CHILD, ['FAMC']],
        'HUSB' => ['FAM', self::SPOUSE, ['FAMS']],
        'WIFE' => ['FAM', self::SPOUSE, ['FAMS']],
    ];

    private Records $records;

    private Profile $profile;

    private ?string $void = null;

    /** @var list<Diagnostic> */
    private array $duplicates = [];

    /** @var list<Diagnostic> */
    private array $pointers = [];

    /**
     * @var array<int, int> (individual << 32 | family) => kinds of link, those of the INDI record
     *      in bits 0–1, those of the FAM record in bits 2–3: a mirrored link is one entry
     */
    private array $linked = [];

    /** Family links to the other type, checked at the end; 16 bytes each: record, node, the record it points to, tag. */
    private string $linksToCheck = '';

    public function begin(Records $records, Profile $profile): void
    {
        $this->records = $records;
        $this->profile = $profile;
        $this->void = $records->version() === Version::V7_0 ? self::VOID : null;
        $this->duplicates = $this->pointers = $this->linked = [];
        $this->linksToCheck = '';
    }

    public function record(Document $document, int $id, int $key): void
    {
        $xref = $document->xrefOf($id);
        $first = $xref === null || $document->isInvalid($id) ? null : $this->records->find($xref);
        if ($first !== null && $first !== $key) {
            $this->duplicates[] = $this->diagnostic($document, 'KV4002', Severity::Error, 'Xref is already used by the record on line ' . $this->records->line($first), $id);
        }
        $tag = $document->tagOf($id);
        $this->pointers($document, $id, $this->definition($this->profile->record($tag)), str_starts_with($tag, '_'));
        if ($xref !== null && $first === $key) {
            $this->familyLinks($document, $id, $key);
        }
    }

    public function end(): array
    {
        $out = [...$this->duplicates, ...$this->pointers];
        $this->unmirroredLinks($out);
        $this->duplicates = $this->pointers = $this->linked = [];
        $this->linksToCheck = '';

        return $out;
    }

    private function pointers(Document $document, int $id, ?Structure $structure, bool $extension): void
    {
        $payload = $document->payloadOf($id);
        if ($payload !== null && $payload !== $this->void && Escaping::isPointer($payload) && !$document->isInvalid($id)) {
            $target = $this->records->find($payload);
            if ($target === null) {
                $this->pointers[] = $this->diagnostic($document, 'KV4001', $extension ? Severity::Warning : Severity::Error, 'Pointer to a record that does not exist', $id);
            } elseif ($structure !== null) {
                $expected = $structure->pointerTarget();
                $tag = $this->records->tag($target);
                if ($expected !== null && $this->profile->record($tag)?->uri !== $expected) {
                    $want = $this->profile->structure($expected)->tag ?? $expected;
                    $this->pointers[] = $this->diagnostic($document, 'KV4003', Severity::Error, "{$structure->tag} must point to a {$want} record, not {$tag}", $id, $structure->uri);
                }
            }
        }
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            $this->pointers($document, $child, $this->definition($structure?->substructure($tag)), $extension || str_starts_with($tag, '_'));
        }
    }

    /**
     * Notes the family links of the first record with an xref; whether they are mirrored is
     * known at the end, without reading the records they point to again.
     */
    private function familyLinks(Document $document, int $id, int $key): void
    {
        $type = $document->tagOf($id);
        if ($type !== 'INDI' && $type !== 'FAM') {
            return;
        }
        $other = $type === 'INDI' ? 'FAM' : 'INDI';
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            $link = self::LINKS[$tag] ?? null;
            $target = $link === null || $link[0] !== $type ? null : $this->records->find((string) $document->payloadOf($child));
            if ($link === null || $target === null) {
                continue;
            }
            // Every link counts as the one pointing back; only those to the other type must have one,
            // and are kept until the end unless the record they point to came first and has it.
            [$pair, $bits, $back] = $type === 'INDI'
                ? [($key << 32) | $target, $link[1], $link[1] << 2]
                : [($target << 32) | $key, $link[1] << 2, $link[1]];
            $this->linked[$pair] = ($this->linked[$pair] ?? 0) | $bits;
            if ($this->records->tag($target) === $other && (($this->linked[$pair] & $back) === 0 || $target > $key)) {
                $this->linksToCheck .= pack('NNNa4', $key, $child, $target, $tag);
            }
        }
    }

    /** @param list<Diagnostic> $out */
    private function unmirroredLinks(array &$out): void
    {
        for ($offset = 0, $end = strlen($this->linksToCheck); $offset < $end; $offset += 16) {
            /** @var array{key: int, node: int, target: int, tag: string} $link */
            $link = unpack('Nkey/Nnode/Ntarget/a4tag', $this->linksToCheck, $offset);
            [$type, $kind, $back] = self::LINKS[rtrim($link['tag'], "\0")];
            $mirrored = $type === 'INDI'
                ? ($this->linked[($link['key'] << 32) | $link['target']] ?? 0) & ($kind << 2)
                : ($this->linked[($link['target'] << 32) | $link['key']] ?? 0) & $kind;
            if ($mirrored !== 0) {
                continue;
            }
            [$document, $id] = $this->records->get($link['key']);
            $out[] = $this->diagnostic($document, 'KV4004', Severity::Warning, $document->payloadOf($link['node']) . ' has no ' . implode(' or ', $back) . ' pointing back to ' . $document->xrefOf($id), $link['node']);
        }
    }

    private function definition(?Substructure $substructure): ?Structure
    {
        return $substructure === null ? null : $this->profile->structure($substructure->uri);
    }

    private function diagnostic(Document $document, string $code, Severity $severity, string $message, int $id, ?string $reference = null): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, $severity, $message, $line > 0 ? $line : null, $document->node($id)->path(), $reference);
    }
}

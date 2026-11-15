<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Spec\Substructure;
use Korin\Gedcom\Syntax\Document;

/**
 * Level 2: every standard tag is allowed where it stands, and substructures occur as often as
 * the profile says (KV2001–KV2003). Extension tags ("_X") and everything under them are not
 * checked; HEAD first and TRLR last are syntax checks (KV1004–KV1006).
 *
 * @internal
 */
final class StructureRule implements Rule
{
    private const CONTINUATION = ['CONC' => true, 'CONT' => true];

    public function check(Document $document, ?Profile $profile): array
    {
        if ($profile === null) {
            return [new Diagnostic('KV2000', Severity::Info, "Structures are not checked: there is no profile for GEDCOM {$document->version()->value} yet")];
        }
        $out = [];
        $this->children($document, $profile, 0, null, $profile->records(), $out);

        return $out;
    }

    /**
     * @param ?Structure $structure null for the top level
     * @param array<string, Substructure> $allowed
     * @param list<Diagnostic> $out
     */
    private function children(Document $document, Profile $profile, int $parent, ?Structure $structure, array $allowed, array &$out): void
    {
        $counts = [];
        $first = [];
        foreach ($document->childIds($parent) as $child) {
            $tag = $document->tagOf($child);
            if ($document->isInvalid($child) || isset(self::CONTINUATION[$tag]) || str_starts_with($tag, '_')) {
                continue;
            }
            $substructure = $allowed[$tag] ?? null;
            if ($substructure === null) {
                $out[] = $this->diagnostic($document, 'KV2001', "{$tag} is not allowed " . ($structure === null ? 'as a record' : "in {$structure->tag}"), $child, $structure?->uri);
                continue;
            }
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            $first[$tag] ??= $child;
            $max = $substructure->cardinality->max;
            if ($max !== null && $counts[$tag] === $max + 1) {
                $out[] = $this->diagnostic($document, 'KV2002', "{$tag} occurs more than {$max} time(s) " . ($structure === null ? 'as a record' : "in {$structure->tag}"), $child, $structure?->uri);
            }
            $definition = $profile->structure($substructure->uri);
            if ($definition !== null) {
                $this->children($document, $profile, $child, $definition, $definition->substructures, $out);
            }
        }
        // The top-level HEAD and TRLR are reported by the parser (KV1004, KV1005).
        if ($structure === null) {
            return;
        }
        foreach ($allowed as $tag => $substructure) {
            if ($substructure->cardinality->isRequired() && ($counts[$tag] ?? 0) < $substructure->cardinality->min) {
                $out[] = $this->diagnostic($document, 'KV2003', "{$structure->tag} requires {$tag}", $parent, $structure->uri);
            }
        }
    }

    private function diagnostic(Document $document, string $code, string $message, int $id, ?string $reference): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, Severity::Error, $message, $line > 0 ? $line : null, $document->node($id)->path(), $reference);
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Records\Records;

/**
 * Level 2: every standard tag is allowed where it stands, and substructures occur as often as
 * the profile says (KV2001–KV2003); 7.0 structures the specification deprecates are warnings
 * (KV2005, values in {@see Payloads7}). 5.x citations and media links are checked by their form
 * (Structure::form()), 7.0 substructures of which one is allowed by Profile::choices(). Extension tags ("_X") and everything under them are not
 * checked; HEAD first and TRLR last are syntax checks (KV1004–KV1006).
 *
 * @internal
 */
final class StructureRule implements Rule
{
    private const CONTINUATION = ['CONC' => true, 'CONT' => true];

    private Profile $profile;

    /** @var array<string, int> records so far by tag */
    private array $counts = [];

    /** @var list<Diagnostic> */
    private array $out = [];

    public function begin(Records $records, Profile $profile): void
    {
        $this->profile = $profile;
        $this->counts = [];
        $this->out = [];
    }

    public function record(Document $document, int $id, int $key): void
    {
        $tag = $document->tagOf($id);
        if ($document->isInvalid($id) || isset(self::CONTINUATION[$tag]) || str_starts_with($tag, '_')) {
            return;
        }
        $substructure = $this->profile->records()[$tag] ?? null;
        if ($substructure === null) {
            $this->out[] = $this->diagnostic($document, 'KV2001', "{$tag} is not allowed as a record", $id, null);

            return;
        }
        $this->counts[$tag] = ($this->counts[$tag] ?? 0) + 1;
        $max = $substructure->cardinality->max;
        if ($max !== null && $this->counts[$tag] === $max + 1) {
            $this->out[] = $this->diagnostic($document, 'KV2002', "{$tag} occurs more than {$max} time(s) as a record", $id, null);
        }
        $definition = $this->profile->structure($substructure->uri);
        if ($definition !== null) {
            $this->children($document, $this->profile, $id, $definition, $definition->formOf($document, $id), $this->out);
        }
    }

    public function end(): array
    {
        foreach ($this->profile->records() as $tag => $substructure) {
            // The top-level HEAD and TRLR are reported by the parser (KV1004, KV1005).
            if ($tag !== 'HEAD' && $tag !== 'TRLR' && $substructure->cardinality->isRequired() && ($this->counts[$tag] ?? 0) < $substructure->cardinality->min) {
                // The file itself has neither a line nor a path.
                $this->out[] = new Diagnostic('KV2003', Severity::Error, "The file requires a {$tag} record", null, null, $substructure->uri);
            }
        }

        return $this->out;
    }

    /**
     * @param ?('pointer'|'inline') $form 5.x: the form of the structure, whose substructures it allows
     * @param list<Diagnostic> $out
     */
    private function children(Document $document, Profile $profile, int $parent, Structure $structure, ?string $form, array &$out): void
    {
        $allowed = $structure->substructuresOf($form);
        $suffix = match ($form) {
            'pointer' => ' with a pointer',
            'inline' => ' without a pointer',
            null => '',
        };
        $counts = [];
        $first = [];
        foreach ($document->childIds($parent) as $child) {
            $tag = $document->tagOf($child);
            if ($document->isInvalid($child) || isset(self::CONTINUATION[$tag]) || str_starts_with($tag, '_')) {
                continue;
            }
            $substructure = $allowed[$tag] ?? null;
            if ($substructure === null) {
                $in = isset($structure->substructures[$tag]) ? $suffix : '';
                $out[] = $this->diagnostic($document, 'KV2001', "{$tag} is not allowed in {$structure->tag}{$in}", $child, $structure->uri);
                continue;
            }
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            $first[$tag] ??= $child;
            $max = $substructure->cardinality->max;
            if ($max !== null && $counts[$tag] === $max + 1) {
                $out[] = $this->diagnostic($document, 'KV2002', "{$tag} occurs more than {$max} time(s) in {$structure->tag}", $child, $structure->uri);
            }
            $this->deprecations($document, $profile, $child, $tag, $substructure->uri, $out);
            $definition = $profile->structure($substructure->uri);
            if ($definition !== null) {
                $this->children($document, $profile, $child, $definition, $definition->formOf($document, $child), $out);
            }
        }
        foreach ($allowed as $tag => $substructure) {
            if ($substructure->cardinality->isRequired() && ($counts[$tag] ?? 0) < $substructure->cardinality->min) {
                $out[] = $this->diagnostic($document, 'KV2003', "{$structure->tag}{$suffix} requires {$tag}", $parent, $structure->uri);
            }
        }
        foreach ($profile->choices($structure->uri) as $tags) {
            $present = array_values(array_intersect_key($first, array_flip($tags)));
            // At the first line of the later one: the extra one in the file.
            if (count($present) > 1) {
                $out[] = $this->diagnostic($document, 'KV2002', "{$structure->tag} allows one of " . implode(' and ', $tags), max($present), $structure->uri);
            }
        }
    }

    /** @param list<Diagnostic> $out */
    private function deprecations(Document $document, Profile $profile, int $id, string $tag, string $uri, array &$out): void
    {
        $use = $profile->deprecation($uri);
        if ($use !== null) {
            $out[] = $this->diagnostic($document, 'KV2005', "{$tag} is deprecated" . ($use === '' ? '' : "; use {$use} instead"), $id, $uri, Severity::Warning);
        }
        $required = $profile->deprecatedWithout($uri);
        if ($required !== null) {
            foreach ($document->childIds($id) as $child) {
                if ($document->tagOf($child) === $required) {
                    return;
                }
            }
            $out[] = $this->diagnostic($document, 'KV2005', "{$tag} without {$required} is deprecated", $id, $uri, Severity::Warning);
        }
    }

    private function diagnostic(Document $document, string $code, string $message, int $id, ?string $reference, Severity $severity = Severity::Error): Diagnostic
    {
        $line = $document->lineOf($id);

        return new Diagnostic($code, $severity, $message, $line > 0 ? $line : null, $document->node($id)->path(), $reference);
    }
}

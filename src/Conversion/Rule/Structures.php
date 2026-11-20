<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * The structures the target version does not have where they stand:
 *
 * - a structure the target has no place for (7.0 → 5.5.1: NO, SDATE, CREA, INIL, CROP,
 *   HEAD.SCHMA, an ASSO of an event, a PHRASE no value rule could join; 5.5.1 → 5.5: EMAIL,
 *   FAX, WWW, MAP, FONE, ROMN; 5.5 → 5.5.1: BLOB, the AGE of a family event; 5.5.1 → 7.0: NOTE
 *   and SOUR of a FONE or ROMN, which become TRAN) becomes an
 *   extension with its tag and its substructures (_NO, _EMAIL), or is dropped under
 *   LossPolicy::DropWithReport (KL2001);
 * - one more than the target allows (5.5 → 5.5.1: a second PEDI; 5.5.1 → 5.5: a second REPO
 *   of a source) likewise (KL2002);
 * - the value Y of an event the target has no value for (7.0 or 5.5 BURI Y → 5.5.1 BURI) is
 *   removed: an event without substructures means the same, one with them is reported (KL3003);
 * - 5.5.5 and 7.0 have no structure with neither a value nor substructures: such a structure
 *   (1 BURI, BIRT Y, whose Y 5.5.5 does not have; 5.5.1 → 7.0: 1 BAPL, an empty SOUR.DATA)
 *   becomes an extension (KL3001);
 * - a record the target does not have (SUBN in 5.5.5) becomes an extension record (KL2001).
 *
 * Converting back gives the structures back (the rule Extensions). The last rule of the walk:
 * it sees the tags the other rules wrote.
 *
 * @internal
 */
final class Structures implements NodeRule
{
    /** @var array<int, ?Structure> target structure of the structures of the record being converted */
    private array $structures = [];

    /** @var array<int, array<string, int>> standard substructures kept so far, by parent and tag, of those with a maximum */
    private array $counts = [];

    /** @var array<string, array<string, array{?int, ?Structure}|false>> by parent structure URI (and 5.x form) and tag: the maximum and the structure, false where the target has none */
    private array $places = [];

    private readonly Profile $profile;

    public function __construct(private readonly Version $target)
    {
        $this->profile = Profile::for($target);
    }

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): ?array
    {
        return null;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $tag = $document->tagOf($id);
        $parent = $document->parentOf($id);
        if ($parent === 0) {
            $uri = $this->profile->record($tag)?->uri;
            $this->structures = [$id => $uri === null ? null : $this->profile->structure($uri)];
            $this->counts = [];
            if ($uri === null && $tag[0] !== '_' && !$document->isInvalid($id)) {
                return $context->unsupported($id, 'KL2001', "{$this->target->value} has no {$tag} record");
            }

            return true;
        }
        $structure = $this->structures[$parent] ?? null;
        if ($structure === null || $tag[0] === '_' || $document->isInvalid($id)) {
            $this->structures[$id] = null;

            return true;
        }
        // 5.x: by the form of the parent (OBJE @O1@ has no TITL, SOUR @S1@ no TEXT).
        $form = $structure->formOf($document, $parent);
        $place = $this->places[$form === null ? $structure->uri : "{$structure->uri} {$form}"][$tag] ??= $this->place($structure, $form, $tag);
        if ($place === false) {
            $this->structures[$id] = null;

            return $context->unsupported($id, 'KL2001', "{$this->target->value} has no {$tag} here");
        }
        [$max, $definition] = $place;
        if ($max !== null) {
            $count = $this->counts[$parent][$tag] ?? 0;
            if ($count >= $max) {
                $this->structures[$id] = null;

                return $context->unsupported($id, 'KL2002', "{$this->target->value} allows {$max} {$tag} here");
            }
            $this->counts[$parent][$tag] = $count + 1;
        }
        $this->structures[$id] = $definition;
        // The value is read only where it decides: most structures have one, or substructures.
        if ($definition?->payload === null && $document->payloadOf($id) === 'Y') {
            $empty = !$this->hasSubstructures($document, $id);
            if ($empty && $this->target === Version::V5_5_5) {
                return $this->bare($id, $tag, $context);
            }
            $document->setPayload($id, null);
            if (!$empty) {
                $context->loss('KL3003', Severity::Warning, $id, StoredAs::Dropped, "{$this->target->value} {$tag} has no value Y");
            }
        } elseif (($this->target === Version::V5_5_5 || $this->target === Version::V7_0) && !$document->hasPayload($id)
            && !$this->hasSubstructures($document, $id) && ($context->value($id) ?? '') === '') {
            return $this->bare($id, $tag, $context);
        }

        return true;
    }

    /**
     * 5.5.5: "every GEDCOM record without subrecords must have a line value" (1 BURI, BIRT Y without its Y);
     * 7.0 has no structure with neither either (1 BAPL, an empty SOUR.DATA; an event gets Y first).
     */
    private function bare(int $id, string $tag, Context $context): bool
    {
        $this->structures[$id] = null;

        return $context->unsupported($id, 'KL3001', "{$this->target->value} {$tag} needs a value or substructures");
    }

    /**
     * @param ?('pointer'|'inline') $form
     *
     * @return array{?int, ?Structure}|false
     */
    private function place(Structure $structure, ?string $form, string $tag): array|false
    {
        $substructure = $structure->substructuresOf($form)[$tag] ?? null;

        return $substructure === null ? false : [$substructure->cardinality->max, $this->profile->structure($substructure->uri)];
    }

    private function hasSubstructures(Document $document, int $id): bool
    {
        foreach ($document->hasChildren($id) ? $document->childIds($id) : [] as $child) {
            $tag = $document->tagOf($child);
            if ($tag !== 'CONC' && $tag !== 'CONT') {
                return true;
            }
        }

        return false;
    }
}

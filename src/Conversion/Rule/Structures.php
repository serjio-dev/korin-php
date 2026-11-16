<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Version;

/**
 * The structures the target version does not have where they stand:
 *
 * - a structure the target has no place for (7.0 → 5.5.1: NO, SDATE, CREA, INIL, CROP,
 *   HEAD.SCHMA, an ASSO of an event, a PHRASE no value rule could join; 5.5.1 → 5.5: EMAIL,
 *   FAX, WWW, MAP, FONE, ROMN; 5.5 → 5.5.1: BLOB, the AGE of a family event) becomes an
 *   extension with its tag and its substructures (_NO, _EMAIL), or is dropped under
 *   LossPolicy::DropWithReport (KL2001);
 * - one more than the target allows (5.5 → 5.5.1: a second PEDI; 5.5.1 → 5.5: a second REPO
 *   of a source) likewise (KL2002);
 * - the value Y of an event the target has no value for (7.0 or 5.5 BURI Y → 5.5.1 BURI) is
 *   removed: an event without substructures means the same, one with them is reported (KL3003);
 * - 5.5.5 has no structure with neither a value nor substructures: such a structure (1 BURI,
 *   BIRT Y, whose Y 5.5.5 does not have) becomes an extension (KL3001);
 * - a record the target does not have (SUBN in 5.5.5) becomes an extension record (KL2001).
 *
 * Converting back gives the structures back (the rule Extensions). The last rule of the walk:
 * it sees the tags the other rules wrote.
 *
 * @internal
 */
final class Structures implements NodeRule
{
    /** @var array<int, ?string> target structure of the structures of the record being converted */
    private array $uris = [];

    /** @var array<string, int> standard substructures kept so far, by parent and tag */
    private array $counts = [];

    public function __construct(private readonly Version $target) {}

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
        $profile = Profile::for($this->target);
        $tag = $document->tagOf($id);
        $parent = $document->parentOf($id);
        if ($parent === 0) {
            $this->uris = [$id => $profile->record($tag)?->uri];
            $this->counts = [];
            if ($this->uris[$id] === null && $tag[0] !== '_' && !$document->isInvalid($id)) {
                return $context->unsupported($id, 'KL2001', "{$this->target->value} has no {$tag} record");
            }

            return true;
        }
        $above = $this->uris[$parent] ?? null;
        if ($above === null || $tag[0] === '_' || $document->isInvalid($id)) {
            $this->uris[$id] = null;

            return true;
        }
        $substructure = $profile->structure($above)?->substructure($tag);
        $this->uris[$id] = $substructure?->uri;
        if ($substructure === null) {
            return $context->unsupported($id, 'KL2001', "{$this->target->value} has no {$tag} here");
        }
        $max = $substructure->cardinality->max;
        $key = $parent . ' ' . $tag;
        if ($max !== null && ($this->counts[$key] ?? 0) >= $max) {
            $this->uris[$id] = null;

            return $context->unsupported($id, 'KL2002', "{$this->target->value} allows {$max} {$tag} here");
        }
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $payload = $document->payloadOf($id);
        $empty = !$this->hasSubstructures($document, $id);
        if ($payload === 'Y' && $profile->structure($substructure->uri)?->payload === null) {
            if ($empty && $this->target === Version::V5_5_5) {
                return $this->bare($id, $tag, $context);
            }
            $document->setPayload($id, null);
            if (!$empty) {
                $context->loss('KL3003', Severity::Warning, $id, StoredAs::Dropped, "{$this->target->value} {$tag} has no value Y");
            }
        } elseif ($empty && $this->target === Version::V5_5_5 && ($context->value($id) ?? '') === '' && ($payload === null || !Escaping::isPointer($payload))) {
            return $this->bare($id, $tag, $context);
        }

        return true;
    }

    /** 5.5.5: "every GEDCOM record without subrecords must have a line value" (1 BURI, BIRT Y without its Y). */
    private function bare(int $id, string $tag, Context $context): bool
    {
        $this->uris[$id] = null;

        return $context->unsupported($id, 'KL3001', "5.5.5 {$tag} needs a value or substructures");
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

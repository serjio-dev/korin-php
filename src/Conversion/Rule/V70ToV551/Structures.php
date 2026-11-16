<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * A structure 5.5.1 does not have where it stands (NO, SDATE, CREA, INIL, CROP, HEAD.SCHMA,
 * MIME and LANG of a note, an ASSO of an event, a PHRASE no value rule could join) becomes an
 * extension with its 7.0 tag and its substructures (_NO, _SDATE), or is dropped under
 * LossPolicy::DropWithReport (KL2001). Converting up gives the 7.0 structure back (the
 * V551ToV70 rule Extensions). The last rule of the walk: it sees the tags the other rules
 * wrote.
 *
 * @internal
 */
final class Structures implements NodeRule
{
    /** @var array<int, ?string> 5.5.1 structure of the structures of the record being converted */
    private array $uris = [];

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
        $profile = Profile::for(Version::V5_5_1);
        $tag = $document->tagOf($id);
        $parent = $document->parentOf($id);
        if ($parent === 0) {
            $this->uris = [$id => $profile->record($tag)?->uri];

            return true;
        }
        $above = $this->uris[$parent] ?? null;
        if ($above === null || $tag[0] === '_' || $document->isInvalid($id)) {
            $this->uris[$id] = null;

            return true;
        }
        $this->uris[$id] = $profile->structure($above)?->substructure($tag)?->uri;
        if ($this->uris[$id] !== null) {
            return true;
        }

        return $context->unsupported($id, 'KL2001', "5.5.1 has no {$tag} here");
    }
}

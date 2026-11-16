<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * An extension named after a 7.0 structure, where 7.0 has that structure and 5.5.1 does not
 * (_NO, _SDATE, _CREA, _INIL, _CROP, _EXID under INDI; _SCHMA under HEAD; _PHRASE under DATE;
 * _FAMC @VOID@), is that structure: it is how a 7.0 structure is written in 5.5.1 (the
 * conversion 7.0 → 5.5.1 does so), and it gets its tag back. Its substructures are already
 * in 7.0 form. SEX U with _SEX X is SEX X. An extension 5.5.1 has the standard structure for
 * (_DATE under BIRT) stays: a program wrote it so on purpose; except a pointer to @VOID@
 * (_FAMC @VOID@), which 5.5.1 cannot write as FAMC. The last rule of the walk: it
 * sees the tags the other rules wrote.
 *
 * @internal
 */
final class Extensions implements NodeRule
{
    /** @var ?array<string, true> the tags of the 7.0 structures */
    private ?array $standard = null;

    public function needsPrepass(): bool
    {
        return false;
    }

    /** "_" and the tag of each 7.0 structure: the walk gives no other structure to this rule. */
    public function tags(): array
    {
        return array_map(static fn(string $tag): string => '_' . $tag, array_keys($this->standard()));
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $tag = $document->tagOf($id);
        $parent = $document->parentOf($id);
        $standard = substr($tag, 1);
        if ($tag[0] !== '_' || $parent === 0 || !isset($this->standard()[$standard]) || $document->isInvalid($id) || $context->inExtension($parent)) {
            return true;
        }
        if ($tag === '_SEX') {
            return $this->sex($document, $id, $parent, $context);
        }
        $tags = [];
        for ($above = $parent; $above !== 0; $above = $document->parentOf($above)) {
            array_unshift($tags, $document->tagOf($above));
        }
        if (Profile::for(Version::V7_0)->resolve($tags)?->substructure($standard) === null
            || ($document->payloadOf($id) !== '@VOID@' && $context->isFromSource($parent) && $context->sourceStructure($parent)?->substructure($standard) !== null)) {
            return true;
        }
        $document->setTag($id, $standard);

        return true;
    }

    /** SEX U with _SEX X, which the conversion to 5.5.1 writes for SEX X. */
    private function sex(Document $document, int $id, int $parent, Context $context): bool
    {
        if ($document->tagOf($parent) !== 'SEX' || $document->payloadOf($parent) !== 'U' || $document->payloadOf($id) !== 'X' || $document->hasChildren($id)) {
            return true;
        }
        $document->removeNode($id);
        $context->setValue($parent, 'X');

        return false;
    }

    /** @return array<string, true> */
    private function standard(): array
    {
        if ($this->standard === null) {
            $tags = $seen = [];
            $profile = Profile::for(Version::V7_0);
            foreach ($profile->records() as $tag => $record) {
                $tags[$tag] = true;
                $this->collect($profile, $record->uri, $tags, $seen);
            }
            $this->standard = $tags;
        }

        return $this->standard;
    }

    /**
     * @param array<string, true> $tags
     * @param array<string, true> $seen
     */
    private function collect(Profile $profile, string $uri, array &$tags, array &$seen): void
    {
        if (isset($seen[$uri])) {
            return;
        }
        $seen[$uri] = true;
        foreach ($profile->structure($uri)->substructures ?? [] as $tag => $substructure) {
            $tags[$tag] = true;
            $this->collect($profile, $substructure->uri, $tags, $seen);
        }
    }
}

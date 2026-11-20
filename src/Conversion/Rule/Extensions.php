<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Spec\Substructure;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * An extension named after a structure of the target version, where the target has that
 * structure and the source could not write it so, is that structure: it is how the conversion
 * the other way writes it (7.0 → 5.5.1: _NO, _SDATE, _CREA, _CROP, _SCHMA under HEAD, _PHRASE
 * under DATE, _FAMC @VOID@; 5.5.1 → 7.0 → 5.5.1: _NOTE and _SOUR under TRAN; 5.5.1 → 5.5: _EMAIL, _FONE, a second REPO of a source as _REPO,
 * SEX U as _SEX U), and it gets its tag back. Its substructures are already in the target's
 * form, unless a step before of the chain made it (Context::restored()). The source could not write it so when it has no such structure there, the value does
 * not fit the source's (an enumeration value it does not list) but fits the target's, the
 * source allows no more of them there, it is empty (5.5.5 and 7.0 have no empty structure), or (to
 * 7.0) it points to @VOID@ or names its family so (_SLGC with FAMC @VOID@); and its value fits the
 * target's (a 5.5 _SOUR with a text under NOTE stays: 7.0 NOTE.SOUR is a pointer). Any other extension
 * (_DATE under BIRT) stays: a program wrote it so on purpose. The last rule of the walk but
 * Structures: it sees the tags the other rules wrote.
 *
 * @internal
 */
final class Extensions implements NodeRule
{
    /** @var ?array<string, list<string>> the tags of the target's structures */
    private ?array $standard = null;

    /** @var ?array<string, array<string, Structure>> the source's structures by tag and URI, in the order of its grammar */
    private ?array $sources = null;

    /** @param bool $grammars whether a value fits a grammar only when it matches it (to and from 5.5.5: dates, ages) */
    public function __construct(
        private readonly Version $from,
        private readonly Version $to,
        private readonly bool $grammars = false,
    ) {}

    public function needsPrepass(): bool
    {
        return false;
    }

    /** "_" and the tag of each structure of the target: the walk gives no other structure to this rule. */
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
        $tags = [];
        for ($above = $parent; $above !== 0; $above = $document->parentOf($above)) {
            array_unshift($tags, $document->tagOf($above));
        }
        $profile = Profile::for($this->to);
        $target = $profile->resolve($tags)?->allowed($document->payloadOf($parent))[$standard] ?? null;
        $payload = $document->payloadOf($id);
        $targetStructure = $target === null ? null : $profile->structure($target->uri);
        // The value must fit too: a 5.5 _SOUR with a text under NOTE is no 7.0 NOTE.SOUR, a pointer.
        if ($target === null || $targetStructure === null || !Payloads::fits($profile, $targetStructure, $payload, $this->grammars)) {
            return true;
        }
        $source = $context->isFromSource($parent) ? $context->sourceStructure($parent)?->allowed($document->payloadOf($parent))[$standard] ?? null : null;
        if ($source !== null && ($this->to !== Version::V7_0 || ($payload !== '@VOID@' && $document->node($id)->first('FAMC')?->pointer() !== '@VOID@'))
            && !$this->notWritable($document, $id, $parent, $standard, $source, $target, $payload)) {
            return true;
        }
        $document->setTag($id, $standard);
        // Its value is in the source's form (7.0 FAM.NCHI.DATE, 5.5.1 _DATE INT 1900 (about then)): the rules of
        // the walk that have not seen it read it as the first source structure of its tag that it fits.
        foreach ($this->sources()[$standard] ?? [] as $uri => $structure) {
            if (Payloads::fits(Profile::for($this->from), $structure, $payload, $this->grammars)) {
                $context->restored($id, $uri);
                break;
            }
        }

        return true;
    }

    /** Whether the source could not write the extension as the structure it has there. */
    private function notWritable(Document $document, int $id, int $parent, string $tag, Substructure $source, Substructure $target, ?string $payload): bool
    {
        // 5.5.5 and 7.0 have no structure with neither a value nor substructures (1 BURI, 1 BAPL).
        if (($this->from === Version::V5_5_5 || $this->from === Version::V7_0) && ($payload === null || $payload === '') && !$document->hasChildren($id)) {
            return true;
        }
        $from = Profile::for($this->from);
        $to = Profile::for($this->to);
        $sourceStructure = $from->structure($source->uri);
        $targetStructure = $to->structure($target->uri);
        if ($sourceStructure !== null && $targetStructure !== null
            && !Payloads::fits($from, $sourceStructure, $payload, $this->grammars) && Payloads::fits($to, $targetStructure, $payload, $this->grammars)) {
            return true;
        }
        $max = $source->cardinality->max;
        if ($max === null) {
            return false;
        }
        $count = 0;
        foreach ($document->childIds($parent) as $sibling) {
            if ($sibling !== $id && $document->tagOf($sibling) === $tag) {
                ++$count;
            }
        }

        return $count >= $max && ($target->cardinality->max === null || $count < $target->cardinality->max);
    }

    /** @return array<string, list<string>> the URIs of the target's structures by tag */
    private function standard(): array
    {
        if ($this->standard === null) {
            $tags = $seen = [];
            $profile = Profile::for($this->to);
            foreach ($profile->records() as $tag => $record) {
                $tags[$tag] = [];
                $this->collect($profile, $record->uri, $tags, $seen);
            }
            $this->standard = $tags;
        }

        return $this->standard;
    }

    /** @return array<string, array<string, Structure>> */
    private function sources(): array
    {
        if ($this->sources === null) {
            $tags = $seen = [];
            $profile = Profile::for($this->from);
            foreach ($profile->records() as $record) {
                $this->collect($profile, $record->uri, $tags, $seen);
            }
            $this->sources = [];
            foreach ($tags as $tag => $uris) {
                foreach ($uris as $uri) {
                    $structure = $profile->structure($uri);
                    if ($structure !== null) {
                        $this->sources[$tag][$uri] = $structure;
                    }
                }
            }
        }

        return $this->sources;
    }

    /**
     * @param array<string, list<string>> $tags the URIs of the structures by tag
     * @param array<string, true> $seen
     */
    private function collect(Profile $profile, string $uri, array &$tags, array &$seen): void
    {
        if (isset($seen[$uri])) {
            return;
        }
        $seen[$uri] = true;
        foreach ($profile->structure($uri)->substructures ?? [] as $tag => $substructure) {
            $tags[$tag][] = $substructure->uri;
            $this->collect($profile, $substructure->uri, $tags, $seen);
        }
    }
}

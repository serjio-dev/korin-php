<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\Values;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * An event with neither a value nor substructures (1 BURI) says that the event occurred, which
 * 7.0 writes as Y: it has no structure without both.
 *
 * @internal
 */
final class Occurrences implements NodeRule
{
    /** @var ?list<string> */
    private ?array $tags = null;

    public function needsPrepass(): bool
    {
        return false;
    }

    /** The tags of the 7.0 structures whose value is Y. */
    public function tags(): array
    {
        if ($this->tags === null) {
            $tags = [];
            $profile = Profile::for(Version::V7_0);
            foreach (Values::structures($profile) as $structure) {
                if ($structure->payload === 'Y|<NULL>') {
                    $tags[$structure->tag] = true;
                }
            }
            $this->tags = array_keys($tags);
        }

        return $this->tags;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        // Children first: most events have some, and a value of the source is decoded to be read.
        if ($document->hasChildren($id) || $document->payloadOf($id) !== null || $document->parentOf($id) === 0 || $context->inExtension($id)) {
            return true;
        }
        $tags = [];
        for ($above = $id; $above !== 0; $above = $document->parentOf($above)) {
            array_unshift($tags, $document->tagOf($above));
        }
        if (Profile::for(Version::V7_0)->resolve($tags)?->payload === 'Y|<NULL>') {
            $document->setPayload($id, 'Y');
        }

        return true;
    }
}

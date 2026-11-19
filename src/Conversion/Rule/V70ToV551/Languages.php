<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Languages as Upgrade;
use Korin\Gedcom\Syntax\Document;

/**
 * HEAD.LANG and SUBM.LANG, the LANG structures 5.5.1 has, take 5.5.1 language names: uk →
 * Ukrainian, "und" with _PHRASE (a name converting up did not know) → that name. A tag 5.5.1
 * has no name for (en-GB, the region) is kept as an extension, _LANG (KL3001), so that nothing
 * is lost and converting up gives it back. A LANG beyond the three 5.5.1 allows in a SUBM
 * keeps its tag: the structures rule keeps it as _LANG in its 7.0 form (KL2002).
 *
 * @internal
 */
final class Languages implements NodeRule
{
    /** SUBM.LANG {0:3} in 5.5.1 */
    private const SUBMITTER_LANGUAGES = 3;

    private const STRUCTURES = ['https://gedcom.io/terms/v7/HEAD-LANG' => true, 'https://gedcom.io/terms/v7/SUBM-LANG' => true];

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['LANG'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (!isset(self::STRUCTURES[$context->sourceStructure($id)->uri ?? ''])) {
            return true;
        }
        $parent = $document->parentOf($id);
        if ($parent !== 0 && $document->parentOf($parent) === 0 && $document->tagOf($parent) === 'SUBM'
            && array_search($id, array_values(array_filter($document->childIds($parent), static fn(int $child): bool => $document->tagOf($child) === 'LANG')), true) >= self::SUBMITTER_LANGUAGES) {
            return true;
        }
        $tag = (string) $context->value($id);
        $phrase = null;
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === '_PHRASE') {
                $phrase = $child;
                break;
            }
        }
        $text = $phrase === null ? null : (string) $context->value($phrase);
        if ($text !== null && $tag === 'und' && Upgrade::tag($text) === null) {
            $document->removeNode($phrase);
            $context->setValue($id, $text);

            return true;
        }
        $name = Upgrade::name($tag);
        if ($name === null || $phrase !== null) {
            return $tag === '' || $context->unsupported($id, 'KL3001', 'The language has no 5.5.1 name');
        }
        $context->setValue($id, $name);

        return true;
    }
}

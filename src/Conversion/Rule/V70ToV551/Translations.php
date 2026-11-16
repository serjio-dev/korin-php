<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * TRAN of a name or place in a language and script 5.5.1 has a variation type for becomes its
 * phonetic or romanized variation, LANG becoming TYPE: ko-Hang → FONE hangul, ja-Kana → FONE
 * kana, ja-Latn → ROMN romaji, zh-Latn-pinyin → ROMN pinyin, zh-Latn-wadegile → ROMN wadegiles;
 * LANG und and und-Latn, which converting up writes for another type, become FONE and ROMN with
 * that type (_TYPE). Any other TRAN is left to the structures rule (_TRAN).
 *
 * @internal
 */
final class Translations implements NodeRule
{
    private const STRUCTURES = ['https://gedcom.io/terms/v7/NAME-TRAN' => true, 'https://gedcom.io/terms/v7/PLAC-TRAN' => true];

    /** BCP 47 => 5.5.1 tag and TYPE; null for the type in _TYPE */
    private const TYPES = [
        'ko-Hang' => ['FONE', 'hangul'],
        'ja-Kana' => ['FONE', 'kana'],
        'ja-Latn' => ['ROMN', 'romaji'],
        'zh-Latn-pinyin' => ['ROMN', 'pinyin'],
        'zh-Latn-wadegile' => ['ROMN', 'wadegiles'],
        'und' => ['FONE', null],
        'und-Latn' => ['ROMN', null],
    ];

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['TRAN'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (!isset(self::STRUCTURES[$context->sourceStructure($id)->uri ?? ''])) {
            return true;
        }
        $language = $other = null;
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($tag === 'LANG') {
                $language ??= $child;
            } elseif ($tag === '_TYPE') {
                $other ??= $child;
            }
        }
        if ($language === null) {
            return true;
        }
        [$tag, $type] = self::TYPES[(string) $context->value($language)] ?? [null, null];
        if ($tag === null || ($type !== null && $other !== null)) {
            return true;
        }
        $document->setTag($id, $tag);
        if ($type !== null) {
            $document->setTag($language, 'TYPE');
            $context->setValue($language, $type);
        } else {
            $document->removeNode($language);
            if ($other !== null) {
                $document->setTag($other, 'TYPE');
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * FONE and ROMN, the phonetic and romanized variations of a name or place, become TRAN with a
 * BCP 47 LANG in place of their TYPE: the types 5.5.1 names map to a language and script
 * (hangul → ko-Hang, kana → ja-Kana, romaji → ja-Latn, pinyin → zh-Latn-pinyin, wadegiles →
 * zh-Latn-wadegile; a FONE type only for FONE, a ROMN type only for ROMN); any other type keeps
 * its value as _TYPE, with LANG und (FONE) or und-Latn (ROMN, a romanization is in Latin
 * script). An empty TYPE is dropped.
 *
 * @internal
 */
final class Translations implements NodeRule
{
    private const ELEMENTS = [
        '<NAME_PHONETIC_VARIATION>' => 'FONE',
        '<PLACE_PHONETIC_VARIATION>' => 'FONE',
        '<NAME_ROMANIZED_VARIATION>' => 'ROMN',
        '<PLACE_ROMANIZED_VARIATION>' => 'ROMN',
    ];

    /** LANG for a type 5.5.1 does not name */
    private const UNKNOWN = ['FONE' => 'und', 'ROMN' => 'und-Latn'];

    /** The types 5.5.1 names, lowercase => BCP 47: phonetic ones for FONE, romanizations for ROMN. */
    private const LANGUAGES = [
        'FONE' => ['hangul' => 'ko-Hang', 'kana' => 'ja-Kana'],
        'ROMN' => ['romaji' => 'ja-Latn', 'pinyin' => 'zh-Latn-pinyin', 'wadegiles' => 'zh-Latn-wadegile'],
    ];

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['FONE', 'ROMN'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $kind = self::ELEMENTS[(string) $context->sourceElement($id)] ?? null;
        if ($kind === null) {
            return true;
        }
        $document->setTag($id, 'TRAN');
        $type = null;
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'TYPE') {
                $type = $child;
                break;
            }
        }
        $value = $type === null ? '' : trim((string) $context->value($type));
        $language = self::LANGUAGES[$kind][strtolower($value)] ?? null;
        if ($type !== null && $language !== null) {
            $document->setTag($type, 'LANG');
            $context->setValue($type, $language);

            return true;
        }
        if ($type !== null && $value === '' && !$document->hasChildren($type)) {
            // An empty TYPE says nothing.
            $document->removeNode($type);
            $type = null;
        } elseif ($type !== null) {
            $document->setTag($type, '_TYPE');
        }
        $context->setValue($document->createNode($id, null, 'LANG', null, $type), self::UNKNOWN[$kind]);

        return true;
    }
}

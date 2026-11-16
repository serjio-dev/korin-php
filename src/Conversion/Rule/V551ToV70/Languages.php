<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;

/**
 * Languages as BCP 47 tags: the 5.5.1 language names (case-insensitive) map to their tags as
 * in the gedcom7code converter, except Dogri, which is "doi" (it writes "dgr", Dogrib). A value
 * that already is a tag of a 2- or 3-letter language ("uk", "en-GB") stays; any other becomes
 * "und" with the value in an extension _PHRASE (7.0 LANG has no PHRASE), as gedcom7code does.
 *
 * @internal
 */
final class Languages implements NodeRule
{
    private const ELEMENTS = ['<LANGUAGE_OF_TEXT>' => true, '<LANGUAGE_PREFERENCE>' => true];

    /** 5.5.1 LANGUAGE_ID, lowercase => BCP 47 */
    private const TAGS = [
        'afrikaans' => 'af', 'albanian' => 'sq', 'amharic' => 'am', 'anglo-saxon' => 'ang', 'arabic' => 'ar',
        'armenian' => 'hy', 'assamese' => 'as', 'belorusian' => 'be', 'bengali' => 'bn', 'braj' => 'bra',
        'bulgarian' => 'bg', 'burmese' => 'my', 'cantonese' => 'yue', 'catalan' => 'ca', 'catalan_spn' => 'ca-ES',
        'church-slavic' => 'cu', 'czech' => 'cs', 'danish' => 'da', 'dogri' => 'doi', 'dutch' => 'nl',
        'english' => 'en', 'esperanto' => 'eo', 'estonian' => 'et', 'faroese' => 'fo', 'finnish' => 'fi',
        'french' => 'fr', 'georgian' => 'ka', 'german' => 'de', 'greek' => 'el', 'gujarati' => 'gu',
        'hawaiian' => 'haw', 'hebrew' => 'he', 'hindi' => 'hi', 'hungarian' => 'hu', 'icelandic' => 'is',
        'indonesian' => 'id', 'italian' => 'it', 'japanese' => 'ja', 'kannada' => 'kn', 'khmer' => 'km',
        'konkani' => 'kok', 'korean' => 'ko', 'lahnda' => 'lah', 'lao' => 'lo', 'latvian' => 'lv',
        'lithuanian' => 'lt', 'macedonian' => 'mk', 'maithili' => 'mai', 'malayalam' => 'ml', 'mandrin' => 'cmn',
        'manipuri' => 'mni', 'marathi' => 'mr', 'mewari' => 'mtr', 'navaho' => 'nv', 'nepali' => 'ne',
        'norwegian' => 'no', 'oriya' => 'or', 'pahari' => 'him', 'pali' => 'pi', 'panjabi' => 'pa',
        'persian' => 'fa', 'polish' => 'pl', 'portuguese' => 'pt', 'prakrit' => 'pra', 'pusto' => 'ps',
        'rajasthani' => 'raj', 'romanian' => 'ro', 'russian' => 'ru', 'sanskrit' => 'sa', 'serb' => 'sr',
        'serbo_croa' => 'sh', 'slovak' => 'sk', 'slovene' => 'sl', 'spanish' => 'es', 'swedish' => 'sv',
        'tagalog' => 'tl', 'tamil' => 'ta', 'telugu' => 'te', 'thai' => 'th', 'tibetan' => 'bo',
        'turkish' => 'tr', 'ukrainian' => 'uk', 'urdu' => 'ur', 'vietnamese' => 'vi', 'wendic' => 'wen',
        'yiddish' => 'yi',
    ];

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
        $value = $context->value($id);
        if ($value === null || $value === '' || !isset(self::ELEMENTS[(string) $context->sourceElement($id)])) {
            return true;
        }
        $tag = self::TAGS[strtolower(trim($value))] ?? null;
        if ($tag === null && preg_match('/^[a-z]{2,3}(?:-|$)/i', $value) === 1 && PayloadGrammar::matches(PayloadGrammar::LANGUAGE, $value)) {
            return true;
        }
        $context->setValue($id, $tag ?? 'und');
        if ($tag === null) {
            $context->addText($id, '_PHRASE', $value);
        }

        return true;
    }
}

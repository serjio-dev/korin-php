<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V40ToV55;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Model\Grammars;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * Values 4.0 writes in words (GEDCOM 4.0, chapter 5), in the form 5.5 has for them:
 *
 * - SEX Male, Female → M, F;
 * - a date with English month names, "Abt.", "Before", "Between … and …", "From … to …"
 *   (11 June 1861, Abt. 1850) → the 5.5 date when it then fits 5.5 (11 JUN 1861, ABT 1850);
 * - any other date that does not fit 5.5, which 4.0 calls irregular and keeps "as it appears in
 *   the source" (4/5 January 1751/52, 7-12-84) → a 5.5 date phrase, "(4/5 January 1751/52)".
 *
 * The meaning is the same and nothing is reported, as with other spellings (5.5 FORM jpeg →
 * 5.5.1 jpg). A date with "@" or a parenthesis stays as it is.
 *
 * @internal
 */
final class Spellings implements NodeRule
{
    private const SEXES = ['MALE' => 'M', 'FEMALE' => 'F'];

    private const MONTHS = [
        'JAN' => 'JANUARY', 'FEB' => 'FEBRUARY', 'MAR' => 'MARCH', 'APR' => 'APRIL', 'MAY' => 'MAY', 'JUN' => 'JUNE',
        'JUL' => 'JULY', 'AUG' => 'AUGUST', 'SEP' => 'SEPTEMBER|SEPT', 'OCT' => 'OCTOBER', 'NOV' => 'NOVEMBER', 'DEC' => 'DECEMBER',
    ];

    private const QUALIFIERS = [
        'ABT' => 'ABOUT|ABT|CIRCA|CA|C', 'BEF' => 'BEFORE|BEF', 'AFT' => 'AFTER|AFT', 'EST' => 'ESTIMATED|EST', 'CAL' => 'CALCULATED|CAL',
    ];

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['SEX', 'DATE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $value = $context->value($id);
        if ($value === null || $context->inExtension($id)) {
            return true;
        }
        $spelled = $document->tagOf($id) === 'SEX' ? self::SEXES[strtoupper(trim($value))] ?? null : self::date($value);
        if ($spelled !== null && $spelled !== $value) {
            $context->setValue($id, $spelled);
        }

        return true;
    }

    /** The 5.5 form of a 4.0 date; null for one that fits 5.5 already, or has "@" or a parenthesis. */
    public static function date(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($value === '' || Grammars::isDate(Version::V5_5, $value) || strpbrk($value, '@()') !== false) {
            return null;
        }
        $spelled = strtoupper($value);
        foreach (self::MONTHS as $month => $names) {
            $spelled = (string) preg_replace("/\\b(?:{$names}|{$month})\\b\\.?/", $month, $spelled);
        }
        foreach (self::QUALIFIERS as $qualifier => $words) {
            $spelled = (string) preg_replace("/^(?:{$words})\\b\\.? ?/", "{$qualifier} ", $spelled);
        }
        $spelled = (string) preg_replace(['/^(?:BETWEEN|BET)\b\.? (.+) AND (.+)$/', '/^FROM (.+) TO (.+)$/'], ['BET $1 AND $2', 'FROM $1 TO $2'], $spelled);
        $spelled = (string) preg_replace('/ ?, ?/', ' ', $spelled);

        return Grammars::isDate(Version::V5_5, $spelled) ? $spelled : "({$value})";
    }
}

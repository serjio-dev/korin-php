<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Dates as Upgrade;
use Korin\Gedcom\Syntax\Document;

/**
 * Dates by the 5.5.1 grammar: calendar names become escapes (JULIAN → @#DJULIAN@, FRENCH_R →
 * @#DFRENCH R@, _ROMAN 24 _IUL 15 → @#DROMAN@ 24 IUL 15), BCE → B.C. A PHRASE joins the date as
 * the 5.5.1 date it came from when converting up: the PHRASE itself when it is that date
 * (DATE 30 JAN 1649 + PHRASE 30 JAN 1648/49 → 30 JAN 1648/49), otherwise "INT date (phrase)",
 * or "(phrase)" without a date (the PHRASE as it is when it has parentheses).
 *
 * Every value is one that converts up to the same date and PHRASE again. A PHRASE that cannot
 * join an approximate date or a range (ABT 1900 + PHRASE) stays for the structures rule; a date
 * with no 5.5.1 form (an extension calendar) is kept as an extension, _DATE (KL3001).
 *
 * @internal
 */
final readonly class Dates implements NodeRule
{
    /** 7.0 date types by structure => 5.5.1 grammar */
    private const GRAMMARS = [
        'https://gedcom.io/terms/v7/DATE' => 'DATE_VALUE',
        'https://gedcom.io/terms/v7/DATE-exact' => 'DATE_EXACT',
        'https://gedcom.io/terms/v7/HEAD-DATE' => 'DATE_EXACT',
        'https://gedcom.io/terms/v7/DATA-EVEN-DATE' => 'DATE_PERIOD',
    ];

    /** 7.0 calendars => 5.5.1 escapes */
    private const CALENDARS = [
        'GREGORIAN' => 'GREGORIAN',
        'JULIAN' => 'JULIAN',
        'HEBREW' => 'HEBREW',
        'FRENCH_R' => 'FRENCH R',
        '_ROMAN' => 'ROMAN',
        '_UNKNOWN' => 'UNKNOWN',
    ];

    private const KEYWORDS = ['ABT' => true, 'CAL' => true, 'EST' => true, 'BEF' => true, 'AFT' => true, 'BET' => true, 'AND' => true, 'FROM' => true, 'TO' => true];

    private Upgrade $upgrade;

    public function __construct()
    {
        $this->upgrade = new Upgrade();
    }

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['DATE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $grammar = self::GRAMMARS[$context->sourceStructure($id)->uri ?? ''] ?? null;
        if ($grammar === null) {
            return true;
        }
        $date = (string) $context->value($id);
        $phrase = null;
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'PHRASE') {
                $phrase = $child;
                break;
            }
        }
        $text = $phrase === null ? null : (string) $context->value($phrase);
        $converted = $this->down($date);
        if ($converted === null) {
            return $context->unsupported($id, 'KL3001', 'The date has no 5.5.1 form');
        }
        $value = $text === null ? null : $this->first($grammar, $converted === '' ? ["({$text})", $text] : [$text, "INT {$converted} ({$text})"], $date, $text);
        if ($value !== null) {
            $document->removeNode($phrase);
        } else {
            $value = $this->first($grammar, [$converted], $date, null);
            if ($value === null) {
                return $date === '' || $context->unsupported($id, 'KL3001', 'The date has no 5.5.1 form');
            }
        }
        if ($value !== $date) {
            $context->setValue($id, $value === '' ? null : $value);
        }

        return true;
    }

    /**
     * The 7.0 date in 5.5.1 words: escapes for calendars, B.C. for BCE, months without "_"; null
     * for a calendar 5.5.1 does not have.
     */
    public function down(string $date): ?string
    {
        $words = [];
        $extension = false;
        foreach ($date === '' ? [] : explode(' ', $date) as $word) {
            if (isset(self::CALENDARS[$word])) {
                $words[] = '@#D' . self::CALENDARS[$word] . '@';
                $extension = str_starts_with($word, '_');
            } elseif ($word === 'BCE') {
                $words[] = 'B.C.';
            } elseif (str_starts_with($word, '_')) {
                if (!$extension) {
                    return null;
                }
                $words[] = substr($word, 1);
            } else {
                $extension = isset(self::KEYWORDS[$word]) ? false : $extension;
                $words[] = $word;
            }
        }

        return implode(' ', $words);
    }

    /**
     * The first value that converts up to the date and PHRASE.
     *
     * @param list<string> $candidates
     */
    private function first(string $grammar, array $candidates, string $date, ?string $phrase): ?string
    {
        foreach ($candidates as $candidate) {
            if (($this->upgrade->convert($grammar, $candidate) ?? [$candidate, null]) === [$date, $phrase]) {
                return $candidate;
            }
        }

        return null;
    }
}

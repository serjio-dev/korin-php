<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\DateGrammar;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;
use Korin\Gedcom\Version;

/**
 * Dates by the 7.0 grammar (specification section 2.6 and appendix A), uppercase:
 *
 * - calendar escapes become calendar names: "@#DJULIAN@" → JULIAN, "@#DFRENCH R@" → FRENCH_R,
 *   "@#DROMAN@" and "@#DUNKNOWN@" → the extension calendars _ROMAN and _UNKNOWN, whose
 *   months become extension months (_ROMAN 24 _IUL 15);
 * - "B.C." → BCE;
 * - a dual year becomes the year it is by a 1 January new year (30 JAN 1648/49 → 30 JAN 1649,
 *   the appendix's example), a dual year whose second part is not the following year the first
 *   one, with the original value as PHRASE;
 * - "INT date (text)" → the date with PHRASE text (the whole value when the date had a dual
 *   year or the text has parentheses); "(text)" → no date, PHRASE text;
 * - a value that is not a 5.5.1 date is kept when it is a 7.0 one ("JULIAN 1650", written
 *   without the escape), otherwise it becomes PHRASE of an empty date.
 *
 * Exact dates (CHAN.DATE, HEAD.DATE) have no PHRASE in 7.0: only their case changes. Days are
 * not checked against the month: the source has KV3008 for that.
 *
 * @internal
 */
final class Dates implements NodeRule
{
    /** 5.5.1 escapes => 7.0 calendars; null for calendars whose months are not defined. */
    private const CALENDARS = [
        'GREGORIAN' => 'GREGORIAN',
        'JULIAN' => 'JULIAN',
        'HEBREW' => 'HEBREW',
        'FRENCH R' => 'FRENCH_R',
        'ROMAN' => '_ROMAN',
        'UNKNOWN' => '_UNKNOWN',
    ];

    private const DATE = '/^(?:@#D([A-Z ]+)@ )?(?:(?:([0-9]+) )?([A-Z][A-Z0-9_]*) )?([0-9]+)(?:\/([0-9]{2}))?( ?B\.C\.)?$/';

    /**
     * Dates written the same in 5.5.1 and 7.0, the usual ones: Gregorian, uppercase, without a
     * phrase or a dual year. A DATE_PERIOD (under SOUR.DATA.EVEN) must still have FROM or TO.
     */
    private const SAME = '/^(?:(?:ABT|CAL|EST|BEF|AFT|FROM|TO) )?(?:(?:(?:[1-9]|[12][0-9]|3[01]) )?(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )?[1-9][0-9]{0,3}$/';

    private ?DateGrammar $grammar = null;

    /** @var array<string, array{months: array<string, true>, bce: bool}> the 7.0 calendars by name */
    private array $calendars = [];

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
        if (!$document->hasChildren($id) && preg_match(self::SAME, (string) $document->payloadOf($id)) === 1 && $document->tagOf($document->parentOf($id)) !== 'EVEN') {
            return true;
        }
        $element = $context->sourceElement($id);
        $value = $context->value($id);
        if ($element === null || $value === null || $value === '') {
            return true;
        }
        $converted = $this->convert((string) Profile::for(Version::V5_5_1)->type($element), $value);
        if ($converted === null) {
            return true;
        }
        [$date, $phrase] = $converted;
        if ($date !== $value) {
            $context->setValue($id, $date === '' ? null : $date);
        }
        if ($phrase !== null) {
            $context->addText($id, 'PHRASE', $phrase);
        }

        return true;
    }

    /**
     * @param string $grammar DATE_VALUE, DATE_PERIOD or DATE_EXACT
     *
     * @return ?array{string, ?string} the 7.0 payload ('' for none) and PHRASE; null when nothing changes
     */
    public function convert(string $grammar, string $value): ?array
    {
        $upper = strtoupper($value);
        if ($grammar === 'DATE_EXACT') {
            return $upper !== $value && $this->grammar()->check(PayloadGrammar::DATE_EXACT, $upper) !== DateGrammar::INVALID ? [$upper, null] : null;
        }
        $converted = $grammar === 'DATE_PERIOD' ? $this->period($upper) : $this->dateValue($value, $upper);
        if ($converted === null) {
            // Not a 5.5.1 date: a 7.0 one stays, anything else is a phrase.
            $type = $grammar === 'DATE_PERIOD' ? PayloadGrammar::DATE_PERIOD : PayloadGrammar::DATE;
            if ($this->grammar()->check($type, $upper) !== DateGrammar::INVALID) {
                return $upper === $value ? null : [$upper, null];
            }

            return ['', $value];
        }
        [$date, $phrase] = $converted;
        if ($phrase === true) {
            $phrase = $value;
        }

        return $date === $value && $phrase === null ? null : [$date, $phrase];
    }

    /** @return ?array{string, string|true|null} the date and its phrase, true for the whole value */
    private function dateValue(string $value, string $upper): ?array
    {
        // A phrase with parentheses inside, "(spring) or (summer)", is kept whole.
        if (preg_match('/^\(([^()]+)\)$/s', $value, $m) === 1) {
            return ['', $m[1]];
        }
        if (preg_match('/^INT (.+?) \((.+)\)$/s', $upper, $m) === 1) {
            $date = $this->date($m[1]);
            if ($date === null) {
                return null;
            }
            $text = substr($value, strlen($m[1]) + 6, -1);

            return [$date[0], $date[1] || strpbrk($text, '()') !== false ? true : $text];
        }
        if (preg_match('/^BET (.+?) AND (.+)$/', $upper, $m) === 1) {
            return $this->compose('BET %s AND %s', $m[1], $m[2]);
        }
        if (preg_match('/^(BEF|AFT|ABT|CAL|EST) (.+)$/', $upper, $m) === 1) {
            return $this->compose($m[1] . ' %s', $m[2]);
        }

        return $this->period($upper) ?? $this->compose('%s', $upper);
    }

    /** @return ?array{string, true|null} */
    private function period(string $upper): ?array
    {
        if (preg_match('/^FROM (.+?) TO (.+)$/', $upper, $m) === 1) {
            return $this->compose('FROM %s TO %s', $m[1], $m[2]);
        }
        if (preg_match('/^(FROM|TO) (.+)$/', $upper, $m) === 1) {
            return $this->compose($m[1] . ' %s', $m[2]);
        }

        return null;
    }

    /**
     * @param string $format the keywords, with %s for each date
     *
     * @return ?array{string, true|null}
     */
    private function compose(string $format, string ...$dates): ?array
    {
        $dual = false;
        foreach ($dates as $i => $date) {
            $converted = $this->date($date);
            if ($converted === null) {
                return null;
            }
            [$dates[$i], $isDual] = $converted;
            $dual = $dual || $isDual;
        }

        return [sprintf($format, ...$dates), $dual ? true : null];
    }

    /** The year after a year, in digits: a year may have more of them than an int holds. */
    private function next(string $year): string
    {
        $year = ltrim($year, '0');
        $i = strlen($year) - 1;
        while ($i >= 0 && $year[$i] === '9') {
            $year[$i] = '0';
            --$i;
        }

        return $i < 0 ? '1' . $year : substr_replace($year, (string) ((int) $year[$i] + 1), $i, 1);
    }

    /** @return ?array{string, bool} the 7.0 date and whether it had a dual year */
    private function date(string $date): ?array
    {
        if (preg_match(self::DATE, $date, $m) !== 1) {
            return null;
        }
        $escape = $m[1];
        $calendar = $escape === '' ? 'GREGORIAN' : (self::CALENDARS[$escape] ?? null);
        if ($calendar === null) {
            return null;
        }
        $day = $m[2];
        $month = $m[3];
        $dual = $m[5] ?? '';
        $bce = ($m[6] ?? '') !== '';
        if (($dual !== '' && $calendar !== 'GREGORIAN') || ($bce && !($this->calendars()[$calendar]['bce'] ?? false))) {
            return null;
        }
        if ($month !== '') {
            if (str_starts_with($calendar, '_')) {
                $month = str_starts_with($month, '_') ? $month : '_' . $month;
            } elseif (!isset($this->calendars()[$calendar]['months'][$month])) {
                return null;
            }
        }
        $next = $dual !== '' && !$bce ? $this->next($m[4]) : null;
        $year = $next !== null && (int) $dual === (int) substr($next, -2) ? $next : $m[4];

        return [
            ($escape === '' ? '' : $calendar . ' ') . ($day === '' ? '' : $day . ' ') . ($month === '' ? '' : $month . ' ') . $year . ($bce ? ' BCE' : ''),
            $dual !== '',
        ];
    }

    private function grammar(): DateGrammar
    {
        return $this->grammar ??= new DateGrammar(Profile::for(Version::V7_0)->calendars());
    }

    /** @return array<string, array{months: array<string, true>, bce: bool}> */
    private function calendars(): array
    {
        if ($this->calendars === []) {
            foreach (Profile::for(Version::V7_0)->calendars() as $name => $calendar) {
                $this->calendars[$name] = ['months' => array_fill_keys($calendar['months'], true), 'bce' => in_array('BCE', $calendar['epochs'], true)];
            }
        }

        return $this->calendars;
    }
}

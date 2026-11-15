<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

/**
 * Grammars of the GEDCOM 5.x primitive elements that spec/5.5.1/primitives.yaml names; dates
 * are in DateGrammar5. Keywords and labels are case-insensitive, as in the gedcom7code tests.
 *
 * @internal
 */
final class Grammar5
{
    public const DATES = ['DATE_VALUE' => true, 'DATE_PERIOD' => true, 'DATE_EXACT' => true];

    /** Human-readable names for messages. */
    public const NAMES = [
        'AGE_AT_EVENT' => 'an age, e.g. "35y 3m", "<1y" or "CHILD"',
        'DATE_VALUE' => 'a date, e.g. "6 DEC 1907", "ABT @#DJULIAN@ 1650" or "(date phrase)"',
        'DATE_PERIOD' => 'a date period, e.g. "FROM 1900 TO 1910" or "TO 1850"',
        'DATE_EXACT' => 'an exact date, e.g. "6 DEC 1907"',
        'NAME_PERSONAL' => 'a personal name (text with at most one /surname/)',
        'NUMBER' => 'a number',
        'PLACE_LATITUDE' => 'a latitude, e.g. "N50.45"',
        'PLACE_LONGITUDE' => 'a longitude, e.g. "E30.52"',
        'TIME_VALUE' => 'a time, e.g. "14:30" or "14:30:15.25"',
    ];

    private const PATTERNS = [
        // [ < | > | <NULL> ] directly before the age; parts in the order y, m, d.
        'AGE_AT_EVENT' => '/^[<>]?(?:[0-9]+y(?: [0-9]+m)?(?: [0-9]+d)?|[0-9]+m(?: [0-9]+d)?|[0-9]+d|CHILD|INFANT|STILLBORN)$/i',
        'NAME_PERSONAL' => '~^(?:[^\x00-\x1F/]+|[^\x00-\x1F/]*/[^\x00-\x1F/]*/[^\x00-\x1F/]*)$~u',
        'NUMBER' => '/^[0-9]+$/',
        'PLACE_LATITUDE' => '/^[NS](?:90|[0-8]?[0-9])(?:\.[0-9]+)?$/i',
        'PLACE_LONGITUDE' => '/^[EW](?:180|1[0-7][0-9]|0?[0-9]?[0-9])(?:\.[0-9]+)?$/i',
        'TIME_VALUE' => '/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]+)?)?$/',
    ];

    /** Whether the value matches the grammar; true for grammars this class does not check. */
    public static function matches(string $grammar, string $value): bool
    {
        return !isset(self::PATTERNS[$grammar]) || preg_match(self::PATTERNS[$grammar], $value) === 1;
    }
}

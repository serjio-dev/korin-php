<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

/**
 * Grammars of the GEDCOM 5.x primitive elements that spec/<version>/primitives.yaml names; dates
 * are in DateGrammar5. Keywords and labels are case-insensitive, as in the gedcom7code tests.
 * 5.5.5 is stricter: "< " and "> " before an age, no zero parts or leading zeroes in ages,
 * times without a leading zero in the hour and with a two-digit fraction.
 *
 * @internal
 */
final class Grammar5
{
    private const DAYS_555 = '(?:[1-9][0-9]?|[12][0-9]{2}|3[0-5][0-9]|36[0-5])d';

    public const DATES = ['DATE_VALUE' => true, 'DATE_PERIOD' => true, 'DATE_EXACT' => true];

    /** Human-readable names for messages. */
    public const NAMES = [
        'AGE_AT_EVENT' => 'an age, e.g. "35y 3m", "<1y" or "CHILD"',
        'DATE_VALUE' => 'a date, e.g. "6 DEC 1907", "ABT @#DJULIAN@ 1650" or "(date phrase)"',
        'DATE_PERIOD' => 'a date period, e.g. "FROM 1900 TO 1910" or "TO 1850"',
        'DATE_EXACT' => 'an exact date, e.g. "6 DEC 1907"',
        'GEDCOM_VERSION_NUMBER' => 'a version number, e.g. "5.5.5"',
        'NAME_PERSONAL' => 'a personal name (text with at most one /surname/)',
        'NUMBER' => 'a number',
        'PLACE_LATITUDE' => 'a latitude, e.g. "N50.45"',
        'PLACE_LONGITUDE' => 'a longitude, e.g. "E30.52"',
        'PLACE_NAME' => 'a place name, parts separated by ", "',
        'PRODUCT_VERSION_NUMBER' => 'a version number, e.g. "2.1" or "10.0.3.64"',
        'TIME_VALUE' => 'a time, e.g. "14:30" or "14:30:15.25"',
    ];

    /** Names that differ in 5.5.5. */
    public const NAMES_555 = [
        'AGE_AT_EVENT' => 'an age, e.g. "35y 3m", "< 1y" or "CHILD"',
    ];

    private const PATTERNS = [
        // [ < | > | <NULL> ] directly before the age; parts in the order y, m, d.
        'AGE_AT_EVENT' => '/^[<>]?(?:[0-9]+y(?: [0-9]+m)?(?: [0-9]+d)?|[0-9]+m(?: [0-9]+d)?|[0-9]+d|CHILD|INFANT|STILLBORN)$/i',
        'NAME_PERSONAL' => '~^(?:[^\x00-\x1F/]+|[^\x00-\x1F/]*/[^\x00-\x1F/]*/[^\x00-\x1F/]*)$~u',
        'NUMBER' => '/^[0-9]+$/',
        'PLACE_LATITUDE' => '/^[NS](?:90|[0-8]?[0-9])(?:\.[0-9]+)?$/i',
        'PLACE_LONGITUDE' => '/^[EW](?:180|1[0-7][0-9]|0?[0-9]?[0-9])(?:\.[0-9]+)?$/i',
        'TIME_VALUE' => '/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]+)?)?$/',
        // MMM.mmm[.rrr], each part 1-3 digits without leading zeroes.
        'GEDCOM_VERSION_NUMBER' => '/^(?:0|[1-9][0-9]{0,2})(?:\.(?:0|[1-9][0-9]{0,2})){1,2}$/',
        'PRODUCT_VERSION_NUMBER' => '/^(?:0|[1-9][0-9]{0,2})(?:\.(?:0|[1-9][0-9]{0,2})){1,3}$/',
        'PLACE_NAME' => '/^[^,]+(?:, [^,]+)*$/u',
    ];

    private const PATTERNS_555 = [
        // [ NULL | < + space | > + space ]; YYY at most 3 digits, MM at most 11, DDD at most 365.
        'AGE_AT_EVENT' => '/^(?:[<>] )?(?:[1-9][0-9]{0,2}y(?: (?:[1-9]|1[01])m)?(?: ' . self::DAYS_555 . ')?|(?:[1-9]|1[01])m(?: ' . self::DAYS_555 . ')?|' . self::DAYS_555 . '|CHILD|INFANT|STILLBORN)$/i',
        // hh without a leading zero, fs two digits.
        'TIME_VALUE' => '/^(?:1?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]{2})?)?$/',
    ];

    /** Whether the value matches the grammar; true for grammars this class does not check. */
    public static function matches(string $grammar, string $value, bool $v555 = false): bool
    {
        $pattern = ($v555 ? self::PATTERNS_555[$grammar] ?? null : null) ?? self::PATTERNS[$grammar] ?? null;

        return $pattern === null || preg_match($pattern, $value) === 1;
    }
}

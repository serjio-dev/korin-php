<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

/**
 * Grammars of the GEDCOM 7.0 data types (specification chapter 2), as regular expressions
 * with linear complexity. Dates are checked by DateGrammar.
 *
 * @internal
 */
final class PayloadGrammar
{
    public const INTEGER = 'http://www.w3.org/2001/XMLSchema#nonNegativeInteger';
    public const ENUM = 'https://gedcom.io/terms/v7/type-Enum';
    public const LIST_ENUM = 'https://gedcom.io/terms/v7/type-List#Enum';
    public const NAME = 'https://gedcom.io/terms/v7/type-Name';
    public const LANGUAGE = 'http://www.w3.org/2001/XMLSchema#Language';
    public const MEDIA_TYPE = 'http://www.w3.org/ns/dcat#mediaType';
    public const AGE = 'https://gedcom.io/terms/v7/type-Age';
    public const TIME = 'https://gedcom.io/terms/v7/type-Time';
    public const LATITUDE = 'https://gedcom.io/terms/v7/type-Latitude';
    public const LONGITUDE = 'https://gedcom.io/terms/v7/type-Longitude';
    public const FILE_PATH = 'https://gedcom.io/terms/v7/type-FilePath';
    public const URI = 'http://www.w3.org/2001/XMLSchema#anyURI';
    public const TAG_DEF = 'https://gedcom.io/terms/v7/type-TagDef';
    public const DATE = 'https://gedcom.io/terms/v7/type-Date';
    public const DATE_PERIOD = 'https://gedcom.io/terms/v7/type-Date#period';
    public const DATE_EXACT = 'https://gedcom.io/terms/v7/type-Date#exact';
    public const Y_OR_NULL = 'Y|<NULL>';

    /** Types whose payload may be omitted (the structure may carry only a PHRASE, say). */
    public const OPTIONAL = [self::AGE => true, self::DATE => true, self::DATE_PERIOD => true, self::Y_OR_NULL => true];

    /** Human-readable names for messages. */
    public const NAMES = [
        self::INTEGER => 'a non-negative integer',
        self::NAME => 'a personal name (text with at most one /surname/)',
        self::LANGUAGE => 'a BCP 47 language tag, e.g. "uk" or "en-US"',
        self::MEDIA_TYPE => 'a media type, e.g. "image/jpeg"',
        self::AGE => 'an age, e.g. "35y 3m" or "< 1y"',
        self::TIME => 'a time, e.g. "14:30" or "14:30:15Z"',
        self::LATITUDE => 'a latitude, e.g. "N50.45"',
        self::LONGITUDE => 'a longitude, e.g. "E30.52"',
        self::FILE_PATH => 'a file path: a relative path or an ftp, http, https or file URL',
        self::URI => 'a URI reference',
        self::TAG_DEF => 'an extension tag and a URI, e.g. "_UID https://…"',
        self::Y_OR_NULL => '"Y" or empty',
        self::DATE => 'a date, e.g. "6 DEC 1907", "ABT JULIAN 1650" or "BET 1900 AND 1910"',
        self::DATE_PERIOD => 'a date period, e.g. "FROM 1900 TO 1910" or "TO 1850"',
        self::DATE_EXACT => 'an exact Gregorian date, e.g. "6 DEC 1907"',
    ];

    public const EXT_TAG = '/^_[A-Z0-9_]+$/';
    private const STD_ENUM = '/^(?:[A-Z][A-Z0-9_]*|[0-9]+)$/';

    private const PATTERNS = [
        self::INTEGER => '/^[0-9]+$/',
        // nameChar is any character but "/" and controls; at most one pair of slashes.
        self::NAME => '~^(?:[^\x00-\x1F/]+|[^\x00-\x1F/]*/[^\x00-\x1F/]*/[^\x00-\x1F/]*)$~u',
        self::AGE => '/^(?:[<>] )?(?:[0-9]+y(?: [0-9]+m)?(?: [0-9]+w)?(?: [0-9]+d)?|[0-9]+m(?: [0-9]+w)?(?: [0-9]+d)?|[0-9]+w(?: [0-9]+d)?|[0-9]+d)$/',
        self::TIME => '/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]+)?)?Z?$/',
        self::LATITUDE => '/^[NS](?:90|[0-8]?[0-9])(?:\.[0-9]+)?$/',
        self::LONGITUDE => '/^[EW](?:180|1[0-7][0-9]|0?[0-9]?[0-9])(?:\.[0-9]+)?$/',
        self::Y_OR_NULL => '/^Y?$/',
    ];

    /** RFC 2045 token: printable ASCII except SPACE and tspecials. */
    private const TOKEN = '[!#$%&\'*+\-.0-9A-Z^_`a-z{|}~]+';

    /** RFC 3986 URI-reference characters, with percent-encoding. */
    private const URI_REFERENCE = '/^(?:[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=]|%[0-9A-Fa-f]{2})*$/';

    /** BCP 47 (RFC 5646) Language-Tag, case-insensitive. */
    private const LANGUAGE_TAG = '/^(?:'
        . '(?:[a-z]{2,3}(?:-[a-z]{3}){0,3}|[a-z]{4}|[a-z]{5,8})' // language, extlang
        . '(?:-[a-z]{4})?'                                      // script
        . '(?:-(?:[a-z]{2}|[0-9]{3}))?'                         // region
        . '(?:-(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3}))*'            // variants
        . '(?:-[0-9a-wy-z](?:-[a-z0-9]{2,8})+)*'                // extensions
        . '(?:-x(?:-[a-z0-9]{1,8})+)?'                          // private use
        . '|x(?:-[a-z0-9]{1,8})+'                               // private use only
        . '|en-gb-oed|i-(?:ami|bnn|default|enochian|hak|klingon|lux|mingo|navajo|pwn|tao|tay|tsu)'
        . '|sgn-(?:be-fr|be-nl|ch-de)|art-lojban|cel-gaulish|no-bok|no-nyn|zh-(?:guoyu|hakka|min|min-nan|xiang)'
        . ')$/i';

    /** Whether the value matches the data type; true for types this class does not check. */
    public static function matches(string $type, string $value): bool
    {
        return match ($type) {
            self::LANGUAGE => preg_match(self::LANGUAGE_TAG, $value) === 1,
            self::MEDIA_TYPE => preg_match('/^' . self::TOKEN . '\/' . self::TOKEN . '(?:[ \t]*;[ \t]*' . self::TOKEN . '=(?:' . self::TOKEN . '|"(?:[^"\\\\\x00-\x1F]|\\\\.)*"))*$/', $value) === 1,
            self::URI => preg_match(self::URI_REFERENCE, $value) === 1,
            self::TAG_DEF => preg_match('/^_[A-Z0-9_]+ (\S+)$/', $value, $m) === 1 && preg_match(self::URI_REFERENCE, $m[1]) === 1,
            self::FILE_PATH => self::isFilePath($value),
            default => !isset(self::PATTERNS[$type]) || preg_match(self::PATTERNS[$type], $value) === 1,
        };
    }

    /**
     * Whether a value is an enumeration value: a standard one from the set, or any extension tag.
     *
     * @param list<string> $permitted
     */
    public static function isEnumValue(string $value, array $permitted): bool
    {
        return preg_match(self::EXT_TAG, $value) === 1 || (preg_match(self::STD_ENUM, $value) === 1 && in_array($value, $permitted, true));
    }

    /** @return list<string> items of a List:Enum: comma-separated, spaces around the commas */
    public static function listItems(string $value): array
    {
        $items = preg_split('/ *, */', $value);

        return $items === false ? [$value] : $items;
    }

    private static function isFilePath(string $value): bool
    {
        // A valid URL string has no spaces, controls or backslashes.
        if ($value === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $value) === 1) {
            return false;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $value, $m) === 1) {
            return in_array(strtolower($m[1]), ['ftp', 'http', 'https', 'file'], true);
        }

        // A local file: relative, without "..", query or fragment.
        return !str_starts_with($value, '/') && !str_contains($value, '?') && !str_contains($value, '#')
            && !in_array('..', explode('/', $value), true) && stripos($value, '%5c') === false && stripos($value, '%2e%2e') === false;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Validation\Rule\Payloads5;
use Korin\Gedcom\Version;

/**
 * Whether a structure of a version can have a payload, as far as the conversion between
 * versions needs to know: a value or none, a pointer or not, and enumeration values; with
 * $grammars, in 5.x the grammars too (dates, ages, times, 5.5.5 place names), as the validator
 * checks them. Without, any text fits a grammar: the value rules convert dates and ages.
 * Lengths (5.5.5 {Size=min:max}, KV3010) are never checked: a value too long for 5.5.5 is still
 * its value.
 *
 * @internal
 */
final class Payloads
{
    /** @var array<string, Payloads5> by version */
    private static array $validators = [];

    /** Values checked lately by version, element and value: at most this many. */
    private const CHECKED = 4096;

    /** @var array<string, bool> */
    private static array $checked = [];

    public static function fits(Profile $profile, Structure $structure, ?string $payload, bool $grammars = false): bool
    {
        $type = $structure->payload;
        if ($payload === null || $payload === '') {
            return $type === null || str_contains($type, '<NULL>');
        }
        if ($type === null) {
            return false;
        }
        if (Escaping::isPointer($payload)) {
            return $structure->pointerTarget() !== null;
        }
        if ($profile->version() === Version::V7_0) {
            if ($type === 'Y|<NULL>') {
                return $payload === 'Y';
            }
            if ($structure->enumerationSet !== null) {
                $listed = $profile->enumeration($structure->enumerationSet);
                $items = str_ends_with($type, 'type-List#Enum') ? array_map(trim(...), explode(',', $payload)) : [$payload];

                return array_filter($items, static fn(string $item): bool => !str_starts_with($item, '_') && !in_array($item, $listed, true)) === [];
            }

            return $structure->pointerTarget() === null || str_contains($type, '|');
        }
        foreach (explode('|', $type) as $alternative) {
            if ($alternative === '<NULL>' || str_starts_with($alternative, '@')) {
                continue;
            }
            if (!str_starts_with($alternative, '<')) {
                if (strcasecmp($alternative, $payload) === 0) {
                    return true;
                }
                continue;
            }
            $type = $profile->type($alternative);
            // A RESN list, as 7.0 writes it and 5.x programs keep it ("confidential, locked").
            $items = $alternative === '<RESTRICTION_NOTICE>' ? array_map(trim(...), explode(',', $payload)) : [$payload];
            if ($type === 'ENUM' ? array_filter($items, static fn(string $item): bool => !self::listed($profile->enumeration($alternative), $item)) === [] : !$grammars || self::valid($profile, $structure, $alternative, $payload)) {
                return true;
            }
        }

        return false;
    }

    /** The enumerated element of a 5.x payload ("<SEX_VALUE>"); null when there is none. */
    public static function enumerated(Profile $profile, Structure $structure): ?string
    {
        foreach (explode('|', (string) $structure->payload) as $alternative) {
            if (str_starts_with($alternative, '<') && $profile->type($alternative) === 'ENUM') {
                return $alternative;
            }
        }

        return null;
    }

    private static function valid(Profile $profile, Structure $structure, string $element, string $value): bool
    {
        $version = $profile->version()->value;
        // Files repeat their dates and places; the grammars are the slow part of a conversion.
        $key = "{$version} {$element} {$value}";
        if (isset(self::$checked[$key])) {
            return self::$checked[$key];
        }
        if (count(self::$checked) >= self::CHECKED) {
            self::$checked = [];
        }
        $validator = self::$validators[$version] ??= new Payloads5($profile);
        foreach ($validator->problems($structure, $element, $value) as [$code, $severity]) {
            if ($severity === Severity::Error && $code !== 'KV3010') {
                return self::$checked[$key] = false;
            }
        }

        return self::$checked[$key] = true;
    }

    /** @param list<string> $permitted */
    private static function listed(array $permitted, string $value): bool
    {
        foreach ($permitted as $option) {
            // "(<ROLE_DESCRIPTOR>)": any text in parentheses.
            if (strcasecmp($option, $value) === 0 || (preg_match('/^\(<[A-Z_]+>\)$/', $option) === 1 && preg_match('/^\(.+\)$/s', $value) === 1)) {
                return true;
            }
        }

        return false;
    }
}

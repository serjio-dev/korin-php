<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Version;

/**
 * Whether a structure of a version can have a payload, as far as the conversion between
 * versions needs to know: a value or none, a pointer or not, and enumeration values. Other
 * grammars (dates, ages) are left to the value rules: any text fits them here.
 *
 * @internal
 */
final class Payloads
{
    public static function fits(Profile $profile, Structure $structure, ?string $payload): bool
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
                return $payload[0] === '_' || in_array($payload, $profile->enumeration($structure->enumerationSet), true);
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
            if ($profile->type($alternative) !== 'ENUM' || self::listed($profile->enumeration($alternative), $payload)) {
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

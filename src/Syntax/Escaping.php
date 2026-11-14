<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Version;

/**
 * "@" escaping of line values. 5.x doubles every "@" except inside "@#…@" escape
 * sequences (calendar escapes such as "@#DJULIAN@"); 7.0 doubles only a leading "@".
 *
 * @internal
 */
final class Escaping
{
    public static function unescape(string $payload, Version $version): string
    {
        if ($version->escapesOnlyLeadingAt()) {
            return str_starts_with($payload, '@@') ? substr($payload, 1) : $payload;
        }

        return str_replace('@@', '@', $payload);
    }

    public static function escape(string $text, Version $version): string
    {
        if ($version->escapesOnlyLeadingAt()) {
            return str_starts_with($text, '@') ? '@' . $text : $text;
        }

        return (string) preg_replace_callback('/@#[^@]*@|@/', static fn(array $m): string => $m[0] === '@' ? '@@' : $m[0], $text);
    }

    /** Whether a line value is a cross-reference pointer such as "@I1@" (not "@@…" or "@#…@"). */
    public static function isPointer(string $payload): bool
    {
        return preg_match('/^@[^@#][^@]*@$/', $payload) === 1;
    }
}

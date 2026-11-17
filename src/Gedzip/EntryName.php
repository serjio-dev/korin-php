<?php

declare(strict_types=1);

namespace Korin\Gedcom\Gedzip;

/**
 * Names of archive entries that are safe to use: relative paths with "/" between segments,
 * none of them "." or "..", no backslash, drive letter or control character. Anything else
 * could write or read outside a directory the archive is extracted to (zip-slip).
 *
 * @internal
 */
final class EntryName
{
    public const DOCUMENT = 'gedcom.ged';

    public static function isSafe(string $name): bool
    {
        if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || preg_match('/[\x00-\x1F]|^[A-Za-z]:/', $name) === 1) {
            return false;
        }
        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The entry a FILE reference names, null for a reference that is not a relative path (a
     * URL, "/x", "//host/x"). References are URIs: "a%20b.jpg" is the entry "a b.jpg".
     */
    public static function ofReference(string $reference): ?string
    {
        if ($reference === '' || $reference[0] === '/' || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $reference) === 1) {
            return null;
        }

        return rawurldecode((string) preg_replace('/[?#].*$/s', '', $reference));
    }
}

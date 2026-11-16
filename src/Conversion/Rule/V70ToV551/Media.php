<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Media as Upgrade;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * Media files by the 5.5.1 types:
 *
 * - FORM, a media type, becomes a file extension: one 5.5.1 lists (image/jpeg → jpg,
 *   image/tiff → tif), another known one (image/png → png), x for application/x-x; a media
 *   type without one stays;
 * - FILE becomes a path: file:///c:/My%20Photos/a.jpg → c:\My Photos\a.jpg, file:///x → /x,
 *   file://server/x → \\server\x, a relative path without its percent-encoding; another URL
 *   (https://…) stays.
 *
 * Every value is one that converts up to the same value again (a percent-encoding may come back
 * in uppercase: https%3a/x is https%3A/x); anything else stays as it is.
 *
 * @internal
 */
final readonly class Media implements NodeRule
{
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
        return ['FORM', 'FILE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $uri = $context->sourceStructure($id)->uri ?? null;
        $value = (string) $context->value($id);
        if ($value === '' || ($uri !== 'https://gedcom.io/terms/v7/FORM' && $uri !== 'https://gedcom.io/terms/v7/FILE')) {
            return true;
        }
        $converted = $uri === 'https://gedcom.io/terms/v7/FORM' ? $this->extension($value) : $this->path($value);
        if ($converted !== $value) {
            $context->setValue($id, $converted);
        }

        return true;
    }

    public function extension(string $type): string
    {
        $listed = Profile::for(Version::V5_5_1)->enumeration('<MULTIMEDIA_FORMAT>');
        $candidates = array_unique([...$listed, ...$this->upgrade->extensions($type), ...(str_starts_with($type, 'application/x-') ? [substr($type, 14)] : [])]);
        foreach ($candidates as $candidate) {
            if ($this->upgrade->mediaType($candidate) === $type) {
                return $candidate;
            }
        }

        return $type;
    }

    public function path(string $url): string
    {
        $candidate = match (true) {
            preg_match('~^file:///([A-Za-z]):/(.*)$~s', $url, $m) === 1 => $m[1] . ':\\' . str_replace('/', '\\', rawurldecode($m[2])),
            preg_match('~^file:///(.*)$~s', $url, $m) === 1 => '/' . rawurldecode($m[1]),
            preg_match('~^file://([^/]+)/(.*)$~s', $url, $m) === 1 => '\\\\' . rawurldecode($m[1]) . '\\' . str_replace('/', '\\', rawurldecode($m[2])),
            preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $url) === 1 => $url,
            default => rawurldecode($url),
        };

        return self::normalize($this->upgrade->url($candidate)) === self::normalize($url) ? $candidate : $url;
    }

    /** Percent-encodings in uppercase: "%3a" and "%3A" are the same URL (RFC 3986, section 6.2.2.1). */
    public static function normalize(string $url): string
    {
        return (string) preg_replace_callback('/%[0-9a-f]{2}/i', static fn(array $m): string => strtoupper($m[0]), $url);
    }
}

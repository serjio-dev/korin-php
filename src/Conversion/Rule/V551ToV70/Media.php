<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;

/**
 * Media files by the 7.0 types:
 *
 * - FORM, a file extension in 5.5.1, becomes a media type: known extensions their registered
 *   type (jpg → image/jpeg, wav → audio/wav), others application/x-<extension>, as gedcom7code
 *   does (other → application/x-other); a value that already is a media type stays;
 * - FILE becomes a URL or a relative path with "/" separators: /x → file:///x,
 *   c:\x → file:///c:/x, \\server\x → file://server/x, a\b → a/b; spaces, "%", "?", "#",
 *   "[", "]" and characters a URL cannot hold are percent-encoded, as is ":" in the first
 *   segment of a relative path (https:/x is not a URL). A URL with a scheme ("http://…") only
 *   has spaces and such characters encoded; ".." stays, though 7.0 does not allow it.
 *
 * @internal
 */
final class Media implements NodeRule
{
    private const TYPES = [
        'avi' => 'video/x-msvideo', 'bmp' => 'image/bmp', 'gif' => 'image/gif', 'heic' => 'image/heic',
        'htm' => 'text/html', 'html' => 'text/html', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg',
        'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg', 'ogg' => 'audio/ogg', 'pcx' => 'image/vnd.zbrush.pcx', 'pdf' => 'application/pdf',
        'png' => 'image/png', 'rtf' => 'application/rtf', 'svg' => 'image/svg+xml', 'tif' => 'image/tiff',
        'tiff' => 'image/tiff', 'txt' => 'text/plain', 'wav' => 'audio/wav', 'webp' => 'image/webp',
    ];

    /** Characters a URL cannot hold. */
    private const UNSAFE = '/[\x00-\x20"<>\\\\^`{|}\x7F]/';

    /** Characters a path cannot hold: besides those, the ones a URL gives a meaning. */
    private const PATH_UNSAFE = '/[\x00-\x20"<>\\\\^`{|}\x7F%?#\[\]]/';

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
        $value = $context->value($id);
        if ($value === null || $value === '') {
            return true;
        }
        $converted = match ($context->sourceElement($id)) {
            '<MULTIMEDIA_FORMAT>' => $this->mediaType($value),
            '<MULTIMEDIA_FILE_REFN>' => $this->url($value),
            default => $value,
        };
        if ($converted !== $value) {
            $context->setValue($id, $converted);
        }

        return true;
    }

    public function mediaType(string $value): string
    {
        $extension = strtolower(ltrim(trim($value), '.'));
        if (isset(self::TYPES[$extension])) {
            return self::TYPES[$extension];
        }
        if (PayloadGrammar::matches(PayloadGrammar::MEDIA_TYPE, $value)) {
            return $value;
        }

        return PayloadGrammar::matches(PayloadGrammar::MEDIA_TYPE, 'application/x-' . $extension) ? 'application/x-' . $extension : $value;
    }

    public function url(string $value): string
    {
        // A drive letter first: c://x is not a URL of scheme "c".
        if (preg_match('~^([A-Za-z]):[\\\\/]+(.*)$~s', $value, $m) === 1) {
            return 'file:///' . $m[1] . ':/' . $this->path($m[2]);
        }
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*://~', $value) === 1) {
            return $this->encode($value, self::UNSAFE);
        }
        if (preg_match('~^(?:\\\\\\\\|//)(.*)$~s', $value, $m) === 1) {
            return 'file://' . $this->path($m[1]);
        }
        if (str_starts_with($value, '/') || str_starts_with($value, '\\')) {
            return 'file:///' . $this->path(substr($value, 1));
        }
        $path = $this->path($value);
        // A first segment with ":" would read as a scheme.
        $slash = strpos($path, '/');
        $first = $slash === false ? $path : substr($path, 0, $slash);

        return str_contains($first, ':') ? str_replace(':', '%3A', $first) . substr($path, strlen($first)) : $path;
    }

    private function path(string $path): string
    {
        return implode('/', array_map(fn(string $segment): string => $this->encode($segment, self::PATH_UNSAFE), explode('/', str_replace('\\', '/', $path))));
    }

    private function encode(string $text, string $unsafe): string
    {
        return (string) preg_replace_callback($unsafe, static fn(array $m): string => rawurlencode($m[0]), $text);
    }
}

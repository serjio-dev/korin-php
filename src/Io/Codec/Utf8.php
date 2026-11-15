<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

/**
 * UTF-8 helpers in pure PHP, so codecs work without ext-mbstring or ext-intl.
 *
 * @internal
 */
final class Utf8
{
    private const SEQUENCE = '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
        . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s';

    public static function isValid(string $bytes): bool
    {
        return preg_match('//u', $bytes) === 1;
    }

    public static function scrub(string $bytes): string
    {
        if (self::isValid($bytes)) {
            return $bytes;
        }
        // Not mb_scrub(): it substitutes mb_substitute_character(), '?' by default.
        return (string) preg_replace_callback(
            self::SEQUENCE,
            static fn(array $m): string => isset($m[1]) ? "\u{FFFD}" : $m[0],
            $bytes,
        );
    }

    /**
     * @return list<int>
     */
    public static function codePoints(string $utf8): array
    {
        $chars = preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new \InvalidArgumentException('Invalid UTF-8');
        }

        return array_map(self::ord(...), $chars);
    }

    public static function ord(string $char): int
    {
        $b = array_values((array) unpack('C*', $char));
        /** @var list<int> $b */
        return match (count($b)) {
            1 => $b[0],
            2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
            3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
            default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
        };
    }

    public static function chr(int $codePoint): string
    {
        return match (true) {
            $codePoint < 0x80 => chr($codePoint),
            $codePoint < 0x800 => chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F)),
            $codePoint < 0x10000 => chr(0xE0 | ($codePoint >> 12)) . chr(0x80 | (($codePoint >> 6) & 0x3F)) . chr(0x80 | ($codePoint & 0x3F)),
            default => chr(0xF0 | ($codePoint >> 18)) . chr(0x80 | (($codePoint >> 12) & 0x3F))
                . chr(0x80 | (($codePoint >> 6) & 0x3F)) . chr(0x80 | ($codePoint & 0x3F)),
        };
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io;

enum Encoding: string
{
    case Utf8 = 'UTF-8';
    case Utf16Le = 'UTF-16LE';
    case Utf16Be = 'UTF-16BE';
    case Ascii = 'ASCII';
    case Ansel = 'ANSEL';
    case Windows1250 = 'Windows-1250';
    case Windows1251 = 'Windows-1251';
    case Windows1252 = 'Windows-1252';
    case Cp437 = 'CP437';
    case Cp850 = 'CP850';
    case MacRoman = 'MacRoman';

    public function bom(): string
    {
        return match ($this) {
            self::Utf8 => "\xEF\xBB\xBF",
            self::Utf16Le => "\xFF\xFE",
            self::Utf16Be => "\xFE\xFF",
            default => '',
        };
    }

    /** Bytes per code unit: line terminators are searched only at multiples of this. */
    public function codeUnitSize(): int
    {
        return $this === self::Utf16Le || $this === self::Utf16Be ? 2 : 1;
    }

    /** Encode an ASCII string (line terminators, tags) in this encoding. */
    public function ascii(string $ascii): string
    {
        return match ($this) {
            self::Utf16Le => implode('', array_map(static fn(string $c): string => $c . "\0", str_split($ascii))),
            self::Utf16Be => implode('', array_map(static fn(string $c): string => "\0" . $c, str_split($ascii))),
            default => $ascii,
        };
    }
}

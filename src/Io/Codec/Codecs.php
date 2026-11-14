<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Io\Encoding;

/** Shared, stateless codec instances per encoding. */
final class Codecs
{
    /** @var array<string, Codec> */
    private static array $instances = [];

    public static function for(Encoding $encoding): Codec
    {
        return self::$instances[$encoding->value] ??= match ($encoding) {
            Encoding::Utf8 => new Utf8Codec(),
            Encoding::Utf16Le => new Utf16Codec(bigEndian: false),
            Encoding::Utf16Be => new Utf16Codec(bigEndian: true),
            Encoding::Ascii => new AsciiCodec(),
            Encoding::Ansel => new AnselCodec(),
            Encoding::Windows1250 => self::table('Windows-1250', 'cp1250'),
            Encoding::Windows1251 => self::table('Windows-1251', 'cp1251'),
            Encoding::Windows1252 => self::table('Windows-1252', 'cp1252'),
            Encoding::Cp437 => self::table('CP437', 'cp437'),
            Encoding::Cp850 => self::table('CP850', 'cp850'),
            Encoding::MacRoman => self::table('MacRoman', 'macroman'),
        };
    }

    private static function table(string $name, string $file): SingleByteCodec
    {
        /** @var array<int, string> $table */
        $table = require __DIR__ . "/../../../resources/encodings/{$file}.php";

        return new SingleByteCodec($name, $table);
    }
}

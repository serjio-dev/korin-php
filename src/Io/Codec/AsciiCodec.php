<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;

/** @internal */
final class AsciiCodec implements Codec
{
    public function decode(string $bytes): ?string
    {
        return preg_match('/[\x80-\xFF]/', $bytes) === 1 ? null : $bytes;
    }

    public function decodeLossy(string $bytes): string
    {
        return (string) preg_replace('/[\x80-\xFF]/', "\u{FFFD}", $bytes);
    }

    public function encode(string $utf8): string
    {
        if (preg_match('/[\x80-\xFF]/', $utf8) === 1) {
            throw new UnencodableCharacterException('Text contains characters outside ASCII');
        }

        return $utf8;
    }
}

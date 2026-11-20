<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;

/** @internal */
final class Utf8Codec implements Codec
{
    public function decode(string $bytes): ?string
    {
        return Utf8::isValid($bytes) ? $bytes : null;
    }

    public function decodeLossy(string $bytes): string
    {
        return Utf8::scrub($bytes);
    }

    public function encode(string $utf8): string
    {
        if (!Utf8::isValid($utf8)) {
            throw new UnencodableCharacterException('Input is not valid UTF-8');
        }

        return $utf8;
    }
}

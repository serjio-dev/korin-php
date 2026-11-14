<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;

/** Converts between one character encoding and UTF-8. */
interface Codec
{
    /** Decodes to UTF-8, or returns null when the bytes are not valid in this encoding. */
    public function decode(string $bytes): ?string;

    /** Decodes to UTF-8, replacing every invalid or undefined sequence with U+FFFD. */
    public function decodeLossy(string $bytes): string;

    /** @throws UnencodableCharacterException */
    public function encode(string $utf8): string;
}

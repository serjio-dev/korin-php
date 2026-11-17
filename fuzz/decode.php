<?php

declare(strict_types=1);

/*
 * Encoding detection and the codecs. The first byte picks the encoding. Decoded text must be
 * UTF-8; what decodes must encode back to the same bytes (ANSEL: to the same text, as it is
 * normalized to NFC).
 *
 * vendor/bin/php-fuzzer fuzz fuzz/decode.php build/fuzz/decode
 */

use Korin\Gedcom\Exception\UnencodableCharacterException;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Codec\Utf8;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Io\EncodingDetector;
use PhpFuzzer\Config;

require __DIR__ . '/../vendor/autoload.php';

/** @var Config $config */
$config->setMaxLen(1024);
$config->setAllowedExceptions([]);
$config->setTarget(static function (string $input): void {
    $encodings = Encoding::cases();
    $encoding = $encodings[ord($input[0] ?? "\0") % count($encodings)];
    $bytes = substr($input, 1);
    (new EncodingDetector())->detect($input);

    $codec = Codecs::for($encoding);
    if (!Utf8::isValid($codec->decodeLossy($bytes))) {
        throw new LogicException('decodeLossy() is not UTF-8');
    }
    $text = $codec->decode($bytes);
    if ($text === null) {
        return;
    }
    if (!Utf8::isValid($text)) {
        throw new LogicException('decode() is not UTF-8');
    }
    try {
        $encoded = $codec->encode($text);
    } catch (UnencodableCharacterException $e) {
        throw new LogicException('Decoded text does not encode back: ' . $e->getMessage(), 0, $e);
    }
    $same = $encoding === Encoding::Ansel ? $codec->decode($encoded) === $text : $encoded === $bytes;
    if (!$same) {
        throw new LogicException('Decoded text encodes to other bytes');
    }
    if (!Utf8::isValid($input)) {
        return;
    }
    try {
        Codecs::for($encoding)->encode($input);
    } catch (UnencodableCharacterException) {
    }
});

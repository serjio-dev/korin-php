<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;

/**
 * ANSEL (ANSI/NISO Z39.47) with the GEDCOM 5.5 additions. Combining marks precede the
 * base letter in ANSEL and follow it in Unicode; decoded text is NFC.
 */
final readonly class AnselCodec implements Codec
{
    /** Spacing characters, byte → UTF-8. */
    public const SPACING = [
        0xA1 => 'Ł', 0xA2 => 'Ø', 0xA3 => 'Đ', 0xA4 => 'Þ', 0xA5 => 'Æ', 0xA6 => 'Œ', 0xA7 => 'ʹ', 0xA8 => '·',
        0xA9 => '♭', 0xAA => '®', 0xAB => '±', 0xAC => 'Ơ', 0xAD => 'Ư', 0xAE => 'ʼ', 0xB0 => 'ʻ', 0xB1 => 'ł',
        0xB2 => 'ø', 0xB3 => 'đ', 0xB4 => 'þ', 0xB5 => 'æ', 0xB6 => 'œ', 0xB7 => 'ʺ', 0xB8 => 'ı', 0xB9 => '£',
        0xBA => 'ð', 0xBC => 'ơ', 0xBD => 'ư', 0xC0 => '°', 0xC1 => 'ℓ', 0xC2 => '℗', 0xC3 => '©', 0xC4 => '♯',
        0xC5 => '¿', 0xC6 => '¡', 0xCF => 'ß',
    ];

    /** Combining marks, byte → UTF-8 of the Unicode combining character. */
    public const COMBINING = [
        0xE0 => "\u{0309}", 0xE1 => "\u{0300}", 0xE2 => "\u{0301}", 0xE3 => "\u{0302}", 0xE4 => "\u{0303}",
        0xE5 => "\u{0304}", 0xE6 => "\u{0306}", 0xE7 => "\u{0307}", 0xE8 => "\u{0308}", 0xE9 => "\u{030C}",
        0xEA => "\u{030A}", 0xEB => "\u{FE20}", 0xEC => "\u{FE21}", 0xED => "\u{0315}", 0xEE => "\u{030B}",
        0xEF => "\u{0310}", 0xF0 => "\u{0327}", 0xF1 => "\u{0328}", 0xF2 => "\u{0323}", 0xF3 => "\u{0324}",
        0xF4 => "\u{0325}", 0xF5 => "\u{0333}", 0xF6 => "\u{0332}", 0xF7 => "\u{0326}", 0xF8 => "\u{031C}",
        0xF9 => "\u{032E}", 0xFA => "\u{FE22}", 0xFB => "\u{FE23}", 0xFE => "\u{0313}",
    ];

    /** @var array<string, string> base . mark → composed */
    private array $compose;

    /** @var array<string, string> composed → base . mark */
    private array $decompose;

    /** @var array<string, int> UTF-8 → byte, spacing and combining */
    private array $encodeMap;

    /**
     * @param bool $useIntl use ext-intl Normalizer when available; false forces the bundled tables
     */
    public function __construct(private bool $useIntl = true)
    {
        /** @var array<string, string> $pairs */
        $pairs = require __DIR__ . '/../../../resources/encodings/ansel-compose.php';
        $this->compose = $pairs;
        $this->decompose = array_flip($pairs);
        $this->encodeMap = array_flip(self::SPACING) + array_flip(self::COMBINING);
    }

    public function decode(string $bytes): ?string
    {
        return $this->convert($bytes, false);
    }

    public function decodeLossy(string $bytes): string
    {
        return (string) $this->convert($bytes, true);
    }

    public function encode(string $utf8): string
    {
        if (preg_match('/[\x80-\xFF]/', $utf8) !== 1) {
            return $utf8;
        }
        $out = '';
        foreach (Utf8::codePoints($this->nfd($utf8)) as $cp) {
            $char = Utf8::chr($cp);
            if ($cp < 0x80) {
                $out .= $char;
            } elseif (isset($this->encodeMap[$char], self::COMBINING[$this->encodeMap[$char]]) && $out !== '') {
                // Unicode puts marks after the base; ANSEL needs them before it. Every ANSEL base is one byte.
                $out = substr($out, 0, -1) . chr($this->encodeMap[$char]) . substr($out, -1);
            } elseif (isset($this->encodeMap[$char])) {
                $out .= chr($this->encodeMap[$char]);
            } else {
                throw new UnencodableCharacterException(sprintf('ANSEL cannot represent U+%04X', $cp));
            }
        }

        return $out;
    }

    private function convert(string $bytes, bool $lossy): ?string
    {
        if (preg_match('/[\x80-\xFF]/', $bytes) !== 1) {
            return $bytes;
        }
        $out = '';
        $marks = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($bytes[$i]);
            if (isset(self::COMBINING[$byte])) {
                $marks .= self::COMBINING[$byte];
                continue;
            }
            if ($byte < 0x80) {
                $char = $bytes[$i];
            } elseif (isset(self::SPACING[$byte])) {
                $char = self::SPACING[$byte];
            } elseif ($lossy) {
                $char = "\u{FFFD}";
            } else {
                return null;
            }
            $out .= $char . $marks;
            $marks = '';
        }
        if ($marks !== '') {
            if (!$lossy) {
                return null;
            }
            $out .= $marks;
        }

        return $this->nfc($out);
    }

    private function nfc(string $utf8): string
    {
        if ($this->useIntl && class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($utf8, \Normalizer::NFC);

            return is_string($normalized) ? $normalized : $utf8;
        }

        return (string) preg_replace_callback(
            '/\X/u',
            function (array $m): string {
                $chars = preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY);
                if ($chars === false) {
                    return $m[0];
                }
                $result = array_shift($chars);
                $left = '';
                foreach ($chars as $mark) {
                    if ($left === '' && isset($this->compose[$result . $mark])) {
                        $result = $this->compose[$result . $mark];
                    } else {
                        $left .= $mark;
                    }
                }

                return $result . $left;
            },
            $utf8,
        );
    }

    private function nfd(string $utf8): string
    {
        if ($this->useIntl && class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($utf8, \Normalizer::NFD);

            return is_string($normalized) ? $normalized : $utf8;
        }

        return (string) preg_replace_callback(
            '/[^\x00-\x7F]/u',
            function (array $m): string {
                $char = $m[0];
                $marks = '';
                while (isset($this->decompose[$char]) && preg_match('/^./su', $this->decompose[$char], $base) === 1) {
                    $marks = substr($this->decompose[$char], strlen($base[0])) . $marks;
                    $char = $base[0];
                }

                return $char . $marks;
            },
            $utf8,
        );
    }
}

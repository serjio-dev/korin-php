<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;

final readonly class Utf16Codec implements Codec
{
    /** @param bool $useMbstring use ext-mbstring to encode when available; false forces the pure-PHP path */
    public function __construct(private bool $bigEndian, private bool $useMbstring = true) {}

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
        if (!Utf8::isValid($utf8)) {
            throw new UnencodableCharacterException('Input is not valid UTF-8');
        }
        if ($this->useMbstring && function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf8, $this->bigEndian ? 'UTF-16BE' : 'UTF-16LE', 'UTF-8');
        }
        $format = $this->bigEndian ? 'n' : 'v';
        $out = '';
        foreach (Utf8::codePoints($utf8) as $cp) {
            if ($cp >= 0x10000) {
                $cp -= 0x10000;
                $out .= pack($format, 0xD800 | ($cp >> 10)) . pack($format, 0xDC00 | ($cp & 0x3FF));
            } else {
                $out .= pack($format, $cp);
            }
        }

        return $out;
    }

    private function convert(string $bytes, bool $lossy): ?string
    {
        if (strlen($bytes) % 2 !== 0) {
            if (!$lossy) {
                return null;
            }
            $bytes = substr($bytes, 0, -1);
            $tail = "\u{FFFD}";
        }
        $units = $bytes === '' ? [] : array_values((array) unpack($this->bigEndian ? 'n*' : 'v*', $bytes));
        /** @var list<int> $units */
        $out = '';
        $count = count($units);
        for ($i = 0; $i < $count; ++$i) {
            $unit = $units[$i];
            if ($unit >= 0xD800 && $unit <= 0xDBFF && $i + 1 < $count && $units[$i + 1] >= 0xDC00 && $units[$i + 1] <= 0xDFFF) {
                $out .= Utf8::chr(0x10000 + (($unit - 0xD800) << 10) + ($units[++$i] - 0xDC00));
            } elseif ($unit >= 0xD800 && $unit <= 0xDFFF) {
                if (!$lossy) {
                    return null;
                }
                $out .= "\u{FFFD}";
            } else {
                $out .= Utf8::chr($unit);
            }
        }

        return $out . ($tail ?? '');
    }
}

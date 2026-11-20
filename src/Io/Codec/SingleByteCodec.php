<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;

/**
 * Code pages whose bytes 0x00–0x7F are ASCII and 0x80–0xFF come from a table.
 *
 * @internal
 */
final readonly class SingleByteCodec implements Codec
{
    /** @var array<string, string> byte → UTF-8 */
    private array $decodeMap;

    /** @var array<string, string> UTF-8 → byte */
    private array $encodeMap;

    private string $undefinedBytes;

    /**
     * @param array<int, string> $table byte (0x80–0xFF) → UTF-8; missing bytes are undefined
     */
    public function __construct(private string $name, array $table)
    {
        $decode = [];
        $undefined = '';
        for ($byte = 0x80; $byte <= 0xFF; ++$byte) {
            if (isset($table[$byte])) {
                $decode[chr($byte)] = $table[$byte];
            } else {
                $undefined .= chr($byte);
            }
        }
        $this->decodeMap = $decode;
        $this->encodeMap = array_flip($decode);
        $this->undefinedBytes = $undefined;
    }

    public function decode(string $bytes): ?string
    {
        if ($this->undefinedBytes !== '' && strpbrk($bytes, $this->undefinedBytes) !== false) {
            return null;
        }

        return strtr($bytes, $this->decodeMap);
    }

    public function decodeLossy(string $bytes): string
    {
        $map = $this->decodeMap;
        foreach (str_split($this->undefinedBytes) as $byte) {
            $map[$byte] = "\u{FFFD}";
        }

        return strtr($bytes, $map);
    }

    public function encode(string $utf8): string
    {
        $unmapped = strtr($utf8, array_fill_keys(array_keys($this->encodeMap), ''));
        if (preg_match('/[\x80-\xFF]/', $unmapped) === 1) {
            throw new UnencodableCharacterException("Text contains characters that {$this->name} cannot represent");
        }

        return strtr($utf8, $this->encodeMap);
    }
}

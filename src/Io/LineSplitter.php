<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io;

/**
 * Splits raw bytes into lines without decoding them, so every line keeps its exact
 * original bytes and terminator (CR, LF, CRLF, or none on the last line).
 *
 * @internal
 */
final class LineSplitter
{
    /**
     * @return \Generator<int, array{int, int, int}> offset, content length, terminator length
     */
    public static function split(string $bytes, Encoding $encoding, int $start = 0): \Generator
    {
        $end = strlen($bytes);
        if ($encoding->codeUnitSize() === 1) {
            for ($pos = $start; $pos < $end;) {
                $length = strcspn($bytes, "\r\n", $pos);
                $eol = $pos + $length >= $end ? 0 : ($bytes[$pos + $length] === "\r" && ($bytes[$pos + $length + 1] ?? '') === "\n" ? 2 : 1);
                yield [$pos, $length, $eol];
                $pos += $length + $eol;
            }

            return;
        }

        $cr = $encoding->ascii("\r");
        $lf = $encoding->ascii("\n");
        for ($pos = $start; $pos < $end;) {
            $next = min(self::aligned($bytes, $cr, $pos, $start), self::aligned($bytes, $lf, $pos, $start));
            if ($next >= $end) {
                yield [$pos, $end - $pos, 0];

                return;
            }
            $eol = substr($bytes, $next, 4) === $cr . $lf ? 4 : 2;
            yield [$pos, $next - $pos, $eol];
            $pos = $next + $eol;
        }
    }

    /** Next occurrence of a two-byte code unit at an even distance from $start, or PHP_INT_MAX. */
    private static function aligned(string $bytes, string $unit, int $from, int $start): int
    {
        while (($found = strpos($bytes, $unit, $from)) !== false) {
            if (($found - $start) % 2 === 0) {
                return $found;
            }
            $from = $found + 1;
        }

        return PHP_INT_MAX;
    }
}

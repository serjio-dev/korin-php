<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Io\Codec\Utf8;
use Korin\Gedcom\Severity;

/**
 * Order: explicit encoding → BOM → UTF-16 byte pattern → HEAD.CHAR checked against content.
 * HEAD.CHAR wins unless the content proves it wrong; a non-standard or ambiguous CHAR
 * (ANSI, LATIN1, …) is resolved from content and reported.
 */
final class EncodingDetector
{
    public const SAMPLE_SIZE = 65536;

    private const STANDARD_CHAR = ['ANSEL', 'ASCII', 'UNICODE', 'UTF-8'];

    public function detect(string $bytes, ?Encoding $forced = null): Detection
    {
        if ($forced !== null) {
            $bom = $forced->bom();

            return new Detection($forced, $bom !== '' && str_starts_with($bytes, $bom), $this->declaredChar($bytes, $forced)[0] ?? null);
        }
        foreach ([Encoding::Utf8, Encoding::Utf16Le, Encoding::Utf16Be] as $encoding) {
            if (str_starts_with($bytes, $encoding->bom())) {
                return new Detection($encoding, true, $this->declaredChar(substr($bytes, strlen($encoding->bom())), $encoding)[0] ?? null);
            }
        }
        // Without a BOM, UTF-16 shows as an ASCII character paired with a zero byte.
        if (preg_match('/^[\x09\x0A\x0D\x20-\x7E]\x00/', $bytes) === 1) {
            return new Detection(Encoding::Utf16Le, false, $this->declaredChar($bytes, Encoding::Utf16Le)[0] ?? null);
        }
        if (preg_match('/^\x00[\x09\x0A\x0D\x20-\x7E]/', $bytes) === 1) {
            return new Detection(Encoding::Utf16Be, false, $this->declaredChar($bytes, Encoding::Utf16Be)[0] ?? null);
        }

        return $this->detectAsciiCompatible($bytes);
    }

    private function detectAsciiCompatible(string $bytes): Detection
    {
        $sample = substr($bytes, 0, self::SAMPLE_SIZE);
        if (strlen($bytes) > self::SAMPLE_SIZE) {
            // Do not judge UTF-8 validity on a sequence cut in half.
            $sample = substr($sample, 0, max((int) strrpos($sample, "\n"), 1));
        }
        [$declared, $line] = $this->declaredChar($bytes, Encoding::Utf8) + [null, null];
        $normalized = $declared === null ? null : strtoupper((string) preg_replace('/[\s_-]+/', '', $declared));
        $mapped = $normalized === null ? null : $this->fromCharName($normalized);
        $diagnostics = [];

        if ($declared !== null && !in_array(strtoupper($declared), self::STANDARD_CHAR, true)) {
            $diagnostics[] = new Diagnostic('KV1101', Severity::Warning, 'Non-standard CHAR value; encoding detected from content', $line, 'HEAD/CHAR');
        }

        if (preg_match('/[\x80-\xFF]/', $sample) !== 1) {
            $encoding = $mapped instanceof Encoding ? $mapped : ($normalized === 'ASCII' ? Encoding::Ascii : Encoding::Utf8);

            return new Detection($encoding, false, $declared, $diagnostics);
        }

        $isUtf8 = Utf8::isValid($sample);
        $contradiction = static fn(string $actual): Diagnostic => new Diagnostic(
            'KV1102',
            Severity::Warning,
            "CHAR {$declared} contradicts the content, which is {$actual}",
            $line,
            'HEAD/CHAR',
        );

        if ($mapped === Encoding::Utf8 || $mapped === 'UNICODE') {
            if ($isUtf8) {
                if ($mapped === 'UNICODE') {
                    $diagnostics[] = $contradiction('UTF-8');
                }

                return new Detection(Encoding::Utf8, false, $declared, $diagnostics);
            }
            $guess = $this->guessSingleByte($sample);
            $diagnostics[] = $contradiction($guess->value);

            return new Detection($guess, false, $declared, $diagnostics);
        }
        if ($isUtf8) {
            if ($mapped !== null) {
                $diagnostics[] = $contradiction('UTF-8');
            }

            return new Detection(Encoding::Utf8, false, $declared, $diagnostics);
        }
        if ($mapped === Encoding::Windows1252 && $this->looksCyrillic($sample)) {
            if ($normalized !== 'ANSI') {
                $diagnostics[] = $contradiction(Encoding::Windows1251->value);
            }

            return new Detection(Encoding::Windows1251, false, $declared, $diagnostics);
        }
        if ($mapped instanceof Encoding && $mapped !== Encoding::Ascii) {
            return new Detection($mapped, false, $declared, $diagnostics);
        }

        $guess = $this->guessSingleByte($sample);
        $diagnostics[] = $mapped === Encoding::Ascii
            ? $contradiction($guess->value)
            : new Diagnostic('KV1103', Severity::Info, "Encoding guessed from content as {$guess->value}", $line, $line !== null ? 'HEAD/CHAR' : null);

        return new Detection($guess, false, $declared, $diagnostics);
    }

    /**
     * Maps a normalized CHAR value to an encoding; 'UNICODE' stays symbolic because it means
     * UTF-16 when the bytes say so and is often misused for UTF-8. ANSI maps to Windows-1252
     * and is refined by content.
     */
    private function fromCharName(string $name): Encoding|string|null
    {
        return match ($name) {
            'UTF8' => Encoding::Utf8,
            'UNICODE', 'UTF16' => 'UNICODE',
            'ASCII', 'USASCII' => Encoding::Ascii,
            'ANSEL' => Encoding::Ansel,
            'ANSI', 'WINDOWS1252', 'CP1252', 'LATIN1', 'ISO88591', 'ISO8859' => Encoding::Windows1252,
            'WINDOWS1251', 'CP1251', 'WIN1251', 'CYRILLIC' => Encoding::Windows1251,
            'WINDOWS1250', 'CP1250', 'WIN1250' => Encoding::Windows1250,
            'IBMPC', 'IBM', 'CP437', 'IBM437' => Encoding::Cp437,
            'CP850', 'IBM850' => Encoding::Cp850,
            'MACINTOSH', 'MACROMAN', 'MAC' => Encoding::MacRoman,
            default => null,
        };
    }

    /**
     * Finds 1 CHAR inside HEAD.
     *
     * @return array{0?: string, 1?: int} declared value and 1-based line number
     */
    private function declaredChar(string $bytes, Encoding $encoding): array
    {
        $sample = substr($bytes, 0, self::SAMPLE_SIZE);
        if ($encoding->codeUnitSize() === 2) {
            $sample = str_replace("\0", '', $sample);
        }
        if (preg_match('/^(.*?)^[ \t]*1[ \t]+CHAR[ \t]+([^\r\n]*)/ms', $sample, $m) !== 1) {
            return [];
        }
        // Only accept CHAR that belongs to HEAD: no level-0 line between HEAD and it.
        if (preg_match_all('/^[ \t]*0[ \t]/m', $m[1]) > 1) {
            return [];
        }

        $breaks = preg_match_all('/\r\n|\r|\n/', $m[1]);

        return [trim($m[2]), ($breaks === false ? 0 : $breaks) + 1];
    }

    private function guessSingleByte(string $sample): Encoding
    {
        return $this->looksCyrillic($sample) ? Encoding::Windows1251 : Encoding::Windows1252;
    }

    /**
     * Cyrillic in Windows-1251 is runs of high bytes (whole words); Latin text in Windows-1252
     * has isolated accented letters between ASCII ones.
     */
    private function looksCyrillic(string $sample): bool
    {
        $high = preg_match_all('/[\x80-\xFF]/', $sample);
        if ($high === 0 || $high === false) {
            return false;
        }
        $inRuns = 0;
        if (preg_match_all('/[\x80-\xFF]{2,}/', $sample, $runs) > 0) {
            $inRuns = array_sum(array_map(strlen(...), $runs[0]));
        }
        $letters = (int) preg_match_all('/[\xC0-\xFF\xA5\xA8\xAA\xAF\xB2-\xB4\xB8\xBA\xBF]/', $sample);

        return $inRuns / $high > 0.5 && $letters / $high > 0.8;
    }
}

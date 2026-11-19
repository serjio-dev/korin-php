<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Io\Codec;

use Korin\Gedcom\Exception\UnencodableCharacterException;
use Korin\Gedcom\Io\Codec\AnselCodec;
use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Codec\Utf16Codec;
use Korin\Gedcom\Io\Codec\Utf8;
use Korin\Gedcom\Io\Encoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodecsTest extends TestCase
{
    /** @return iterable<string, array{Encoding, string}> */
    public static function roundTrips(): iterable
    {
        yield 'UTF-8' => [Encoding::Utf8, 'Ґанок 👪'];
        yield 'UTF-16LE surrogates' => [Encoding::Utf16Le, 'Ґ 𝔊 👪'];
        yield 'UTF-16BE' => [Encoding::Utf16Be, 'Їжак'];
        yield 'Windows-1251' => [Encoding::Windows1251, 'ґ Ґ є Є і І ї Ї ’ «» №'];
        yield 'Windows-1252' => [Encoding::Windows1252, 'Françoise € „quoted“'];
        yield 'Windows-1250' => [Encoding::Windows1250, 'Dvořák Łucja'];
        yield 'CP437' => [Encoding::Cp437, 'Müller ░▒▓'];
        yield 'CP850' => [Encoding::Cp850, 'Ångström ø'];
        yield 'MacRoman' => [Encoding::MacRoman, 'Crème brûlée'];
        yield 'ANSEL' => [Encoding::Ansel, 'Wałęsa Dvořák Søren © 5°'];
    }

    #[Test]
    #[DataProvider('roundTrips')]
    public function encodesAndDecodesBack(Encoding $encoding, string $text): void
    {
        $codec = Codecs::for($encoding);

        self::assertSame($text, $codec->decode($codec->encode($text)));
    }

    #[Test]
    public function anselPutsCombiningMarksBeforeTheBaseLetter(): void
    {
        // e + ogonek = F1 65; r + caron = E9 72
        self::assertSame("Wa\xB1\xF1esa \xE9r", Codecs::for(Encoding::Ansel)->encode('Wałęsa ř'));
        self::assertSame('Wałęsa ř', Codecs::for(Encoding::Ansel)->decode("Wa\xB1\xF1esa \xE9r"));
    }

    #[Test]
    public function anselWithoutIntlUsesBundledCompositionTables(): void
    {
        $codec = new AnselCodec(useIntl: false);

        self::assertSame('Żółć Ångström', $codec->decode($codec->encode('Żółć Ångström')));
        self::assertSame("\xE7Z\xE2o\xB1\xE2c", $codec->encode('Żółć'));
    }

    #[Test]
    public function anselHandlesTwoMarksOnOneLetter(): void
    {
        // ệ = e + dot below + circumflex; both marks precede the base.
        $codec = Codecs::for(Encoding::Ansel);

        self::assertSame('ệ', $codec->decode($codec->encode('ệ')));
    }

    /** Found by fuzzing: NFD splits Ơ, ơ, Ư, ư into a letter and a horn ANSEL has no mark for. */
    #[Test]
    public function anselLettersWithHornAreSpacingCharacters(): void
    {
        foreach ([Codecs::for(Encoding::Ansel), new AnselCodec(useIntl: false)] as $codec) {
            self::assertSame("\xAC\xBC\xAD\xBD", $codec->encode('ƠơƯư'));
            self::assertSame("Vi\xF2\xE3et \xE0\xBC \xF2\xBD", $codec->encode('Việt ở ự'));
            self::assertSame('Việt ở ự', $codec->decode($codec->encode('Việt ở ự')));
        }
    }

    #[Test]
    public function invalidBytesAreRejectedOrReplaced(): void
    {
        $utf8 = Codecs::for(Encoding::Utf8);
        self::assertNull($utf8->decode("ab\xC3"));
        self::assertSame("ab\u{FFFD}", $utf8->decodeLossy("ab\xC3"));

        // 0x81 is undefined in Windows-1252.
        self::assertNull(Codecs::for(Encoding::Windows1252)->decode("a\x81"));
        self::assertSame("a\u{FFFD}", Codecs::for(Encoding::Windows1252)->decodeLossy("a\x81"));

        // A lone high surrogate.
        self::assertNull((new Utf16Codec(bigEndian: false))->decode("\x3D\xD8a\x00"));
        self::assertNull(Codecs::for(Encoding::Ansel)->decode("a\xE2"));
    }

    #[Test]
    public function unencodableCharactersThrowInsteadOfBecomingQuestionMarks(): void
    {
        $this->expectException(UnencodableCharacterException::class);

        Codecs::for(Encoding::Windows1252)->encode('Ґ');
    }

    #[Test]
    public function utf16WithoutMbstringEncodesTheSame(): void
    {
        foreach ([true, false] as $bigEndian) {
            $text = 'Ґ 𝔊 👪 a';
            self::assertSame((new Utf16Codec($bigEndian))->encode($text), (new Utf16Codec($bigEndian, useMbstring: false))->encode($text));
        }
        self::assertSame("\x3D\xD8\x6A\xDC", (new Utf16Codec(bigEndian: false, useMbstring: false))->encode('👪'));
    }

    #[Test]
    public function utf16RejectsOrReplacesWhatIsNoUtf16(): void
    {
        $codec = new Utf16Codec(bigEndian: false);
        // An odd number of bytes: the last one is no code unit.
        self::assertNull($codec->decode("a\x00b"));
        self::assertSame("a\u{FFFD}", $codec->decodeLossy("a\x00b"));
        // A lone surrogate.
        self::assertSame("\u{FFFD}a", $codec->decodeLossy("\x3D\xD8a\x00"));
        self::assertSame('', $codec->decodeLossy(''));

        $this->expectException(UnencodableCharacterException::class);
        $codec->encode("a\xC3");
    }

    #[Test]
    public function asciiHasNoBytesAbove127(): void
    {
        $codec = Codecs::for(Encoding::Ascii);
        self::assertSame('Anna', $codec->decode('Anna'));
        self::assertNull($codec->decode("Ann\xE4"));
        self::assertSame("Ann\u{FFFD}", $codec->decodeLossy("Ann\xE4"));
        self::assertSame('Anna', $codec->encode('Anna'));

        $this->expectException(UnencodableCharacterException::class);
        $codec->encode('Anné');
    }

    #[Test]
    public function anselReplacesWhatItCannotDecodeAndRejectsWhatItCannotEncode(): void
    {
        $codec = Codecs::for(Encoding::Ansel);
        // 0xAF is not assigned; a combining mark at the end has no letter to go on.
        self::assertSame("a\u{FFFD}", $codec->decodeLossy("a\xAF"));
        self::assertNull($codec->decode("a\xE2"));
        self::assertSame("\u{00E1}", $codec->decodeLossy("a\xE2"));
        // Two marks the bundled tables cannot compose into one letter stay after it.
        self::assertSame("q\u{0301}\u{0300}", (new AnselCodec(useIntl: false))->decode("\xE2\xE1q"));

        $this->expectException(UnencodableCharacterException::class);
        $codec->encode('Ґ');
    }

    #[Test]
    public function pureUtf8HelpersWorkWithoutExtensions(): void
    {
        self::assertSame([0x0490, 0x1F46A], Utf8::codePoints('Ґ👪'));
        self::assertSame('👪', Utf8::chr(0x1F46A));
        self::assertSame("a\u{FFFD}b", Utf8::scrub("a\xFFb"));
        self::assertSame('Ґанок', Utf8::scrub('Ґанок'));
        self::assertSame([0x20AC], Utf8::codePoints('€'));
        self::assertSame('', Codecs::for(Encoding::Utf8)->decodeLossy(''));

        $this->expectException(\InvalidArgumentException::class);
        Utf8::codePoints("a\xFF");
    }

    #[Test]
    public function utf8OrdAndChrOnEveryLengthBoundary(): void
    {
        $chars = [
            0x00 => "\x00", 0x7F => "\x7F", 0x80 => "\xC2\x80", 0x7FF => "\xDF\xBF", 0x800 => "\xE0\xA0\x80",
            0x0490 => "\xD2\x90", 0x20AC => "\xE2\x82\xAC", 0xFFFF => "\xEF\xBF\xBF", 0x10000 => "\xF0\x90\x80\x80",
            0x1F46A => "\xF0\x9F\x91\xAA", 0x10FFFF => "\xF4\x8F\xBF\xBF",
        ];
        foreach ($chars as $codePoint => $bytes) {
            self::assertSame($bytes, Utf8::chr($codePoint), sprintf('U+%04X', $codePoint));
            self::assertSame($codePoint, Utf8::ord($bytes), sprintf('U+%04X', $codePoint));
        }
        self::assertSame("a\u{FFFD}", Utf8::scrub("a\u{FFFD}"));
    }

    #[Test]
    public function utf8EncodesOnlyValidUtf8(): void
    {
        self::assertSame('Ґ', Codecs::for(Encoding::Utf8)->encode('Ґ'));

        $this->expectException(UnencodableCharacterException::class);
        Codecs::for(Encoding::Utf8)->encode("a\xFF");
    }
}

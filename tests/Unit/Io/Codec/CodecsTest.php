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
    public function pureUtf8HelpersWorkWithoutExtensions(): void
    {
        self::assertSame([0x0490, 0x1F46A], Utf8::codePoints('Ґ👪'));
        self::assertSame('👪', Utf8::chr(0x1F46A));
        self::assertSame("a\u{FFFD}b", Utf8::scrub("a\xFFb"));
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValueRuleTest extends TestCase
{
    #[Test]
    public function payloadsAreCheckedAgainstTheirTypes(): void
    {
        $doc = Gedcom::parse(
            "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 SEX Female\n1 NAME A/B/C/D\n1 BIRT\n2 AGE 35 years\n1 DEAT Y\n"
            . "1 RESN CONFIDENTIAL, LOCKED, secret\n1 FAMC\n1 SNOTE text\n0 @S1@ SNOTE @I1@\n0 @O1@ OBJE\n"
            . "1 FILE C:\\photos\\a.jpg\n2 FORM jpeg\n1 FILE media/a.jpg\n2 FORM image/jpeg\n0 @N1@ SUBM\n1 NAME Me\n"
            . "1 LANG Ukrainian\n1 ADDR\n2 CITY Kyiv\n0 TRLR\n",
        );

        self::assertSame([
            'KV3006 error line 5 (@I1@/SEX): SEX does not permit "Female"; expected one of F, M, U, X or an extension value',
            'KV3005 error line 6 (@I1@/NAME): NAME must be a personal name (text with at most one /surname/)',
            'KV3005 error line 8 (@I1@/BIRT/AGE): AGE must be an age, e.g. "35y 3m" or "< 1y"',
            'KV3006 error line 10 (@I1@/RESN): RESN does not permit "secret"; expected one of CONFIDENTIAL, LOCKED, PRIVACY or an extension value',
            'KV3007 error line 11 (@I1@/FAMC): FAMC has neither a value nor substructures',
            'KV3003 error line 12 (@I1@/SNOTE): SNOTE must be a pointer to a record',
            'KV3004 error line 13 (@S1@): SNOTE must have a value, not a pointer',
            'KV3005 error line 15 (@O1@/FILE[0]): FILE must be a file path: a relative path or an ftp, http, https or file URL',
            'KV3005 error line 16 (@O1@/FILE[0]/FORM): FORM must be a media type, e.g. "image/jpeg"',
            'KV3005 error line 21 (@N1@/LANG): LANG must be a BCP 47 language tag, e.g. "uk" or "en-US"',
            'KV3001 error line 22 (@N1@/ADDR): ADDR requires a value',
        ], array_map(static fn(Diagnostic $d): string => (string) $d, Gedcom::validate($doc)->diagnostics()));
    }

    #[Test]
    public function optionalPayloadsAndExtensionsPass(): void
    {
        $doc = Gedcom::parse(
            "0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 SEX _OTHER\n1 BIRT\n2 DATE\n3 PHRASE Spring, year unknown\n"
            . "2 AGE\n3 PHRASE young\n1 DEAT Y\n1 NOTE First line\n2 CONT second\n1 _EXT\n2 SEX anything\n0 TRLR\n",
        );

        self::assertSame([], Gedcom::validate($doc)->diagnostics());
    }

    #[Test]
    public function longEnumerationValuesAreShortenedInMessages(): void
    {
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 SEX " . str_repeat('Q', 50) . "\n0 TRLR\n");

        self::assertStringContainsString('"' . str_repeat('Q', 19) . '…"', Gedcom::validate($doc)->diagnostics()[0]->message);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function values(): iterable
    {
        yield 'age bound' => [PayloadGrammar::AGE, '> 99y 11m 30d', true];
        yield 'age weeks' => [PayloadGrammar::AGE, '8w 30d', true];
        yield 'age without space after bound' => [PayloadGrammar::AGE, '<1y', false];
        yield 'age order' => [PayloadGrammar::AGE, '3m 1y', false];
        yield 'time' => [PayloadGrammar::TIME, '2:50', true];
        yield 'time utc fraction' => [PayloadGrammar::TIME, '23:59:59.125Z', true];
        yield 'time 24' => [PayloadGrammar::TIME, '24:00', false];
        yield 'latitude' => [PayloadGrammar::LATITUDE, 'N50.4501', true];
        yield 'latitude over 90' => [PayloadGrammar::LATITUDE, 'S91', false];
        yield 'longitude' => [PayloadGrammar::LONGITUDE, 'W180', true];
        yield 'longitude over 180' => [PayloadGrammar::LONGITUDE, 'E181.5', false];
        yield 'longitude sign' => [PayloadGrammar::LONGITUDE, '-30.5', false];
        yield 'language' => [PayloadGrammar::LANGUAGE, 'uk-UA', true];
        yield 'language script' => [PayloadGrammar::LANGUAGE, 'sr-Latn-RS', true];
        yield 'language grandfathered' => [PayloadGrammar::LANGUAGE, 'i-klingon', true];
        yield 'language name' => [PayloadGrammar::LANGUAGE, 'Ukrainian', false];
        yield 'media type' => [PayloadGrammar::MEDIA_TYPE, 'text/html; charset=utf-8', true];
        yield 'media type without subtype' => [PayloadGrammar::MEDIA_TYPE, 'image', false];
        yield 'file relative' => [PayloadGrammar::FILE_PATH, 'media/anna%20koval.jpg', true];
        yield 'file url' => [PayloadGrammar::FILE_PATH, 'https://example.com/a.jpg', true];
        yield 'file absolute' => [PayloadGrammar::FILE_PATH, '/home/a.jpg', false];
        yield 'file parent' => [PayloadGrammar::FILE_PATH, 'media/../a.jpg', false];
        yield 'file other scheme' => [PayloadGrammar::FILE_PATH, 'mailto:a@example.com', false];
        yield 'uri' => [PayloadGrammar::URI, 'https://example.com/terms#_UID', true];
        yield 'uri with space' => [PayloadGrammar::URI, 'https://example.com/a b', false];
        yield 'tag definition' => [PayloadGrammar::TAG_DEF, '_SKYPEID http://xmlns.com/foaf/0.1/skypeID', true];
        yield 'tag definition standard tag' => [PayloadGrammar::TAG_DEF, 'NAME http://example.com', false];
        yield 'name' => [PayloadGrammar::NAME, 'Анна /Коваль/ молодша', true];
        yield 'name without surname' => [PayloadGrammar::NAME, 'Анна', true];
        yield 'name line break' => [PayloadGrammar::NAME, "Анна\n/Коваль/", false];
        yield 'integer' => [PayloadGrammar::INTEGER, '007', true];
        yield 'negative integer' => [PayloadGrammar::INTEGER, '-1', false];
    }

    #[Test]
    #[DataProvider('values')]
    public function grammars(string $type, string $value, bool $valid): void
    {
        self::assertSame($valid, PayloadGrammar::matches($type, $value));
    }
}

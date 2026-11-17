<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit;

use Korin\Gedcom\Conversion\Rule\V40ToV55\Spellings;
use Korin\Gedcom\Exception\UnsupportedVersionException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use Korin\Gedcom\WriteOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    private const V40 = "0 HEAD\n1 SOUR PAF\n1 GEDC\n2 VERS 4.0\n0 @1@ INDI\n1 SEX Male\n0 TRLR\n";

    #[Test]
    public function mapsDeclaredVersions(): void
    {
        self::assertSame(Version::V7_0, Version::fromDeclared('7.0.18'));
        self::assertSame(Version::V7_0, Version::fromDeclared('7.0'));
        self::assertSame(Version::V5_5_1, Version::fromDeclared(' 5.5.1 '));
        self::assertSame(Version::V5_5, Version::fromDeclared('5.3'));
        self::assertSame(Version::V5_5, Version::fromDeclared('5.01'));
        self::assertSame(Version::V4_0, Version::fromDeclared('4.0'));
        self::assertSame(Version::V4_0, Version::fromDeclared('4.0.4.18'));
        self::assertSame(Version::V4_0, Version::fromDeclared('4+'));
        self::assertNull(Version::fromDeclared('7.1'));
        self::assertNull(Version::fromDeclared('6.0'));
        self::assertNull(Version::fromDeclared('3.0'));
        self::assertNull(Version::fromDeclared('5.05'));
    }

    #[Test]
    public function oldVersionsAreReadWithThe55Profile(): void
    {
        $document = Gedcom::parse(self::V40);
        $draft = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.3\n0 TRLR\n");
        $final = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5\n0 TRLR\n");

        self::assertSame(Version::V4_0, $document->version());
        self::assertSame(Version::V5_5, Version::V4_0->readAs());
        self::assertSame(['KV1009 info line 4 (HEAD/GEDC/VERS): GEDCOM 4.0 is read with the 5.5 profile'], array_map(strval(...), $document->diagnostics()));
        self::assertSame(['KV1009'], array_map(static fn(\Korin\Gedcom\Diagnostic $d): string => $d->code, $draft->diagnostics()));
        self::assertSame([], $final->diagnostics());
    }

    #[Test]
    public function version40IsWrittenOnlyUnchanged(): void
    {
        $document = Gedcom::parse(self::V40);
        $document->record('@1@')?->first('SEX')?->setValue('Female');

        self::assertSame(str_replace('Male', 'Female', self::V40), Gedcom::serialize($document));
        $this->expectException(UnsupportedVersionException::class);
        Gedcom::serialize($document, WriteOptions::normalize());
    }

    #[Test]
    public function nothingConvertsTo40(): void
    {
        $this->expectExceptionObject(new UnsupportedVersionException('GEDCOM 4.0 is read only: convert to 5.5 or later'));
        Gedcom::convert(Gedcom::parse(self::V40), Version::V4_0);
    }

    #[Test]
    public function version40ConvertsTo55AndOn(): void
    {
        $result = Gedcom::convert(Gedcom::parse(self::V40), Version::V5_5_1);

        self::assertStringContainsString("2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @1@ INDI\n1 SEX M\n", $result->output());
        self::assertSame(Version::V4_0, $result->losses()->from);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function dates(): iterable
    {
        yield 'month in words' => ['11 June 1861', '11 JUN 1861'];
        yield 'abbreviation with a period' => ['3 Sept. 1890', '3 SEP 1890'];
        yield 'about' => ['Abt. 1850', 'ABT 1850'];
        yield 'circa' => ['c. 1700', 'ABT 1700'];
        yield 'before' => ['Before 3 MAR 1913', 'BEF 3 MAR 1913'];
        yield 'between' => ['Between 1904 and 1905', 'BET 1904 AND 1905'];
        yield 'already 5.5' => ['Dec 1881', null];
        yield 'dual year and day' => ['4/5 January 1751/52', '(4/5 January 1751/52)'];
        yield 'ambiguous' => ['7-12-84', '(7-12-84)'];
        yield 'feast' => ['2 days after Easter, 1690', '(2 days after Easter, 1690)'];
        yield 'month first' => ['Sept. 3, 1890', '(Sept. 3, 1890)'];
        yield 'calendar escape' => ['@#DHebrew@26TAM5442', null];
        yield 'parenthesis' => ['1900 (bapt.)', null];
    }

    #[Test]
    #[DataProvider('dates')]
    public function datesOf40Take55Form(string $date, ?string $expected): void
    {
        self::assertSame($expected, Spellings::date($date));
    }
}

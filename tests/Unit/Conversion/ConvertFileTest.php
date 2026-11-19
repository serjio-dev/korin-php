<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConvertFileTest extends TestCase
{
    private const HEAD_551 = "0 HEAD\n1 SOUR Test\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n";

    /** @return iterable<string, array{string, Version}> */
    public static function files(): iterable
    {
        yield 'SUBN after the records, to 7.0' => [self::HEAD_551 . "1 SUBN @S1@\n0 @I1@ INDI\n1 NAME A\n0 @S1@ SUBN\n1 TEMP SLAKE\n0 @S2@ SUBN\n1 FAMF x\n0 TRLR\n", Version::V7_0];
        yield 'SUBN after the records, to 5.5.5' => [self::HEAD_551 . "1 SUBM @U1@\n0 @I1@ INDI\n0 @U1@ SUBM\n1 NAME U\n0 @S1@ SUBN\n1 TEMP SLAKE\n0 TRLR\n", Version::V5_5_5];
        yield 'submitter at the end, to 5.5.5' => [self::HEAD_551 . "1 SUBM @U2@\n0 @I1@ INDI\n1 OBJE\n2 FILE a.jpg\n2 FORM jpg\n0 @U1@ SUBM\n1 NAME One\n0 @U2@ SUBM\n1 NAME Two\n1 OBJE\n2 FILE b.jpg\n2 FORM jpg\n0 TRLR\n", Version::V5_5_5];
        yield 'first submitter without HEAD.SUBM, to 5.5.5' => [self::HEAD_551 . "0 @I1@ INDI\n0 SUBM\n1 NAME Nameless\n0 @U1@ SUBM\n1 NAME One\n0 TRLR\n", Version::V5_5_5];
        yield 'HEAD.SUBM to a record that is not SUBM, to 5.5.5' => [self::HEAD_551 . "1 SUBM @I1@\n0 @I1@ INDI\n0 @U1@ SUBM\n1 NAME One\n0 TRLR\n", Version::V5_5_5];
        yield 'inline records after TRLR, to 7.0' => [self::HEAD_551 . "0 @I1@ INDI\n1 SOUR Inline one\n0 TRLR\n0 @I2@ INDI\n1 SOUR Inline two\n1 OBJE\n2 FILE c.png\n2 FORM png\n0 TRLR\n", Version::V7_0];
        yield 'xrefs renamed, dangling pointers, to 7.0' => [self::HEAD_551 . "1 SUBM @sub@\n0 @fam 1@ FAM\n1 HUSB @I1@\n0 @I1@ INDI\n1 FAMS @fam 1@\n1 FAMC @lost@\n0 @sub@ SUBM\n1 NAME S\n0 @note@ NOTE A @@ note\n0 TRLR\n", Version::V7_0];
        yield 'LANG without a BCP 47 tag, to 7.0' => [self::HEAD_551 . "1 LANG Klingon\n0 @I1@ INDI\n1 _PHRASE p\n0 TRLR\n", Version::V7_0];
        yield 'HEAD._SUBN, to 5.5.5' => ["0 HEAD\n1 GEDC\n2 VERS 7.0\n1 _SUBN\n2 TEMP SLAKE\n0 @I1@ INDI\n1 SEX X\n0 TRLR\n", Version::V5_5_5];
        yield 'HEAD._SUBN, to 5.5' => ["0 HEAD\n1 GEDC\n2 VERS 7.0\n1 _SUBN\n2 TEMP SLAKE\n0 @I1@ INDI\n1 NAME Анна /Коваль/\n0 TRLR\n", Version::V5_5];
        yield 'no TRLR, to 7.0' => [self::HEAD_551 . "0 @I1@ INDI\n1 SOUR Inline\n", Version::V7_0];
        yield 'HEAD not first, to 7.0' => ["0 @I1@ INDI\n1 SOUR Inline\n" . self::HEAD_551 . "0 TRLR\n", Version::V7_0];
        yield 'lines that are not GEDCOM lines, CRLF, to 5.5' => [str_replace("\n", "\r\n", self::HEAD_551 . "0 @I1@ INDI\nnot a line\n1 EMAIL a@b.c\n0 TRLR\n"), Version::V5_5];
        yield 'SUBN right after HEAD, to 7.0' => [self::HEAD_551 . "0 @S1@ SUBN\n1 TEMP SLAKE\n0 @I1@ INDI\n0 TRLR\n", Version::V7_0];
        yield 'SUBM right after HEAD, to 5.5.5' => [self::HEAD_551 . "0 @U1@ SUBM\n1 NAME One\n0 @I1@ INDI\n0 @U2@ SUBM\n1 NAME Two\n0 TRLR\n", Version::V5_5_5];
        yield 'HEAD.SUBM to the third submitter, to 5.5.5' => [self::HEAD_551 . "1 SUBM @U3@\n0 @I1@ INDI\n0 @U1@ SUBM\n1 NAME One\n0 @U2@ SUBM\n1 NAME Two\n0 @U3@ SUBM\n1 NAME Three\n0 TRLR\n", Version::V5_5_5];
        yield 'two SUBN and a SUBM, to 7.0' => [self::HEAD_551 . "1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME U\n0 @S1@ SUBN\n1 TEMP SLAKE\n0 @I1@ INDI\n0 @S2@ SUBN\n1 FAMF y\n0 TRLR\n", Version::V7_0];
        yield 'LANG of HEAD and SUBM, to 7.0' => [self::HEAD_551 . "1 LANG Ukrainian\n1 SUBM @U1@\n0 @I1@ INDI\n1 NAME A\n0 @U1@ SUBM\n1 NAME U\n1 LANG English\n1 LANG Klingon\n0 TRLR\n", Version::V7_0];
        yield 'extensions with a schema, to 5.5.1' => ["0 HEAD\n1 GEDC\n2 VERS 7.0\n1 SCHMA\n2 TAG _SKYPEID http://xmlns.com/foaf/0.1/skypeID\n0 @I1@ INDI\n1 _SKYPEID x\n1 _UID y\n1 SEX X\n0 @F1@ FAM\n1 _STAT z\n0 TRLR\n", Version::V5_5_1];
        yield 'same version' => ["\xEF\xBB\xBF0 HEAD\n1 GEDC\n2 VERS 7.0\n0 TRLR", Version::V7_0];
    }

    #[Test]
    #[DataProvider('files')]
    public function outputAndLossesAreThoseOfConvert(string $bytes, Version $to): void
    {
        $result = Gedcom::convert(Gedcom::parse($bytes), $to);

        $out = $this->handle('');
        $losses = Gedcom::convertFile($this->handle($bytes), $out, $to);
        rewind($out);

        self::assertSame($result->output(), stream_get_contents($out));
        self::assertSame(json_encode($result->losses(), JSON_PRETTY_PRINT), json_encode($losses, JSON_PRETTY_PRINT));
    }

    #[Test]
    public function outputIsWrittenToAPath(): void
    {
        $in = (string) tempnam(sys_get_temp_dir(), 'korin');
        $out = (string) tempnam(sys_get_temp_dir(), 'korin');
        file_put_contents($in, self::HEAD_551 . "0 @I1@ INDI\n1 SEX U\n0 TRLR\n");
        try {
            $losses = Gedcom::convertFile($in, $out, Version::V5_5);

            self::assertSame(Gedcom::convert(Gedcom::read($in), Version::V5_5)->output(), file_get_contents($out));
            self::assertCount(1, $losses);
        } finally {
            unlink($in);
            unlink($out);
        }
    }

    #[Test]
    public function failPolicyThrowsAtTheFirstLoss(): void
    {
        $this->expectException(ConversionException::class);

        Gedcom::convertFile($this->handle(self::HEAD_551 . "0 @I1@ INDI\n1 SEX U\n0 TRLR\n"), $this->handle(''), Version::V5_5, (new ConvertOptions())->withPolicy(LossPolicy::Fail));
    }

    /** @return resource */
    private function handle(string $bytes): mixed
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertNotFalse($handle);
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }
}

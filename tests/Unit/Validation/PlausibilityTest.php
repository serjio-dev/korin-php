<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Validation\Rule\DateRange;
use Korin\Gedcom\Validation\ValidationOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlausibilityTest extends TestCase
{
    private const HEAD_70 = "0 HEAD\n1 GEDC\n2 VERS 7.0\n";
    private const HEAD_551 = "0 HEAD\n1 SOUR Korin\n1 SUBM @U1@\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @U1@ SUBM\n1 NAME Korin\n";

    /** @return iterable<string, array{string, ?array{int, int}}> */
    public static function ranges(): iterable
    {
        yield 'day' => ['1 JAN 2000', [2451545, 2451545]];
        yield 'month' => ['FEB 2000', [2451576, 2451604]];
        yield 'year' => ['2000', [2451545, 2451910]];
        yield 'Julian, the day before the Gregorian reform' => ['JULIAN 4 OCT 1582', [2299160, 2299160]];
        yield '5.x calendar escape' => ['@#DJULIAN@ 4 OCT 1582', [2299160, 2299160]];
        yield 'Gregorian reform' => ['15 OCT 1582', [2299161, 2299161]];
        yield 'dual year is the second' => ['1 JAN 1699/00', [2341973, 2341973]];
        yield 'BCE is year 0' => ['1 JAN 1 BCE', [1721060, 1721060]];
        yield 'Julian BCE' => ['JULIAN 1 JAN 1 BCE', [1721058, 1721058]];
        yield '5.x B.C.' => ['1 JAN 1 B.C.', [1721060, 1721060]];
        yield 'day beyond the month is its last day' => ['31 NOV 1999', [2451513, 2451513]];
        yield 'between' => ['BET 1999 AND 2000', [2451180, 2451910]];
        yield 'before' => ['BEF 2000', [PHP_INT_MIN, 2451910]];
        yield 'after' => ['AFT 2000', [2451545, PHP_INT_MAX]];
        yield 'period' => ['FROM 1999 TO 2000', [2451180, 2451910]];
        yield 'interpreted' => ['INT 2000 (about then)', [2451545, 2451910]];
        yield 'lower case' => ['bef 2000', [PHP_INT_MIN, 2451910]];
        yield 'approximate' => ['ABT 2000', null];
        yield 'phrase' => ['(in the war)', null];
        yield 'other calendar' => ['HEBREW 5760', null];
        yield 'unknown month' => ['1 XYZ 2000', null];
        yield 'not a date' => ['yesterday', null];
        yield 'spaces around' => ['  2000 ', [2451545, 2451910]];
        yield 'text before INT' => ['X INT 2000 (then)', null];
        yield 'interpreted, phrase of two lines' => ["INT 2000 (a\nb)", [2451545, 2451910]];
        yield 'text before BET' => ['XBET 1999 AND 2000', null];
        yield 'text before BEF' => ['XBEF 2000', null];
        yield 'between with an end of another calendar' => ['BET 1999 AND HEBREW 5760', null];
        yield 'between with a start of another calendar' => ['BET HEBREW 5760 AND 2000', null];
        yield 'from, to' => ['TO 2000', [PHP_INT_MIN, 2451910]];
        yield 'from' => ['FROM 2000', [2451545, PHP_INT_MAX]];
        yield 'before another calendar' => ['BEF HEBREW 5760', null];
        yield 'Julian with a 5.x escape and year' => ['@#DJULIAN@ 1582', [2298884, 2299248]];
        yield 'dual year in the same century' => ['1 JAN 1648/49', [2323346, 2323346]];
    }

    /** @param ?array{int, int} $expected */
    #[Test]
    #[DataProvider('ranges')]
    public function dateRange(string $value, ?array $expected): void
    {
        self::assertSame($expected, DateRange::of($value));
    }

    #[Test]
    public function plausibilityIsOptional(): void
    {
        $doc = Gedcom::parse(self::HEAD_70 . "0 @I1@ INDI\n1 BIRT\n2 DATE 1950\n1 DEAT\n2 DATE 1900\n0 TRLR\n");

        self::assertSame([], Gedcom::validate($doc)->diagnostics());
        self::assertSame(
            ['KV5001 warning line 6 (@I1@/BIRT/DATE): Birth is after the death on line 8'],
            $this->lines($doc),
        );
        self::assertTrue(Gedcom::validate($doc, (new ValidationOptions())->withPlausibility())->isValid());
    }

    #[Test]
    public function birthAfterDeathOnlyWhenCertain(): void
    {
        $doc = Gedcom::parse(self::HEAD_70
            . "0 @I1@ INDI\n1 BIRT\n2 DATE 1900\n1 DEAT\n2 DATE 1900\n"            // the same year
            . "0 @I2@ INDI\n1 BIRT\n2 DATE AFT 1900\n1 DEAT\n2 DATE BEF 1950\n"    // may overlap
            . "0 @I3@ INDI\n1 BIRT\n2 DATE ABT 1950\n1 DEAT\n2 DATE 1900\n"        // approximate
            . "0 @I4@ INDI\n1 BIRT\n2 DATE (later)\n1 BIRT\n2 DATE 2 JAN 1900\n1 DEAT\n2 DATE 1 JAN 1900\n"
            . "0 @I5@ INDI\n1 BIRT\n2 DATE AFT 1950\n1 DEAT\n2 DATE BEF 1949\n"
            . "0 TRLR\n");

        self::assertSame([
            'KV5001 warning line 23 (@I4@/BIRT[1]/DATE): Birth is after the death on line 25',
            'KV5001 warning line 28 (@I5@/BIRT/DATE): Birth is after the death on line 30',
        ], $this->lines($doc));
    }

    #[Test]
    public function aRangeThatNamesItsEndFirstSpansTheSameDays(): void
    {
        $doc = Gedcom::parse(self::HEAD_70
            . "0 @I1@ INDI\n1 BIRT\n2 DATE BET 1950 AND 92 BCE\n1 DEAT\n2 DATE 1940\n"   // may be before
            . "0 @I2@ INDI\n1 BIRT\n2 DATE BET 1960 AND 1950\n1 DEAT\n2 DATE 1940\n"     // certainly after
            . "0 TRLR\n");

        self::assertSame(['KV5001 warning line 11 (@I2@/BIRT/DATE): Birth is after the death on line 13'], $this->lines($doc));
    }

    #[Test]
    public function childBornBeforeAParent(): void
    {
        $doc = Gedcom::parse(self::HEAD_551
            . "0 @I1@ INDI\n1 BIRT\n2 DATE 1950\n1 FAMS @F1@\n"
            . "0 @I2@ INDI\n1 BIRT\n2 DATE @#DJULIAN@ 1890\n1 FAMS @F1@\n"
            . "0 @I3@ INDI\n1 BIRT\n2 DATE 12 MAR 1920\n1 FAMC @F1@\n"
            . "0 @I4@ INDI\n1 BIRT\n2 DATE BET 1940 AND 1960\n1 FAMC @F1@\n"
            . "0 @F1@ FAM\n1 HUSB @I1@\n1 WIFE @I2@\n1 CHIL @I3@\n1 CHIL @I4@\n"
            . "0 TRLR\n");

        self::assertSame(['KV5002 warning line 29 (@F1@/CHIL[0]): The child is born before HUSB @I1@'], $this->lines($doc));
    }

    #[Test]
    public function ownAncestor(): void
    {
        $doc = Gedcom::parse(self::HEAD_70
            . "0 @I1@ INDI\n1 FAMC @F1@\n"
            . "0 @I2@ INDI\n1 FAMS @F1@\n"
            . "0 @I3@ INDI\n1 FAMS @F2@\n1 FAMC @F3@\n"
            . "0 @I4@ INDI\n1 FAMS @F3@\n1 FAMC @F2@\n"
            . "0 @I5@ INDI\n1 FAMS @F4@\n1 FAMC @F4@\n"
            . "0 @F1@ FAM\n1 WIFE @I2@\n1 CHIL @I1@\n"
            . "0 @F2@ FAM\n1 HUSB @I3@\n1 CHIL @I4@\n"
            . "0 @F3@ FAM\n1 WIFE @I4@\n"                  // I3 a child of F3 only by FAMC
            . "0 @F4@ FAM\n1 HUSB @I5@\n1 CHIL @I5@\n"
            . "0 TRLR\n");

        self::assertSame([
            'KV5003 warning line 8 (@I3@): The individual is their own ancestor (a cycle of 2 individuals)',
            'KV5003 warning line 14 (@I5@): The individual is their own ancestor (a cycle of 1 individual)',
        ], $this->lines($doc));
    }

    #[Test]
    public function boundariesAndLinksOnlyOneSideHas(): void
    {
        $doc = Gedcom::parse(self::HEAD_70
            . "0 @I1@ INDI\n1 BIRT\n2 DATE 31 DEC 1900\n1 DEAT\n2 DATE 1900\n"   // the last day the death can be
            . "0 @I2@ INDI\n1 BIRT\n2 DATE 1 JAN 1950\n"
            . "0 @I3@ INDI\n1 BIRT\n2 DATE 1 JAN 1950\n"
            . "0 @F1@ FAM\n1 HUSB @I2@\n1 CHIL @I3@\n"                              // born the same day
            // A cycle of CHIL lines only, through the second family of @I4@.
            . "0 @I4@ INDI\n0 @I5@ INDI\n0 @I6@ INDI\n"
            . "0 @F2@ FAM\n1 HUSB @I5@\n1 CHIL @I4@\n"
            . "0 @F3@ FAM\n1 WIFE @I6@\n1 CHIL @I4@\n"
            . "0 @F4@ FAM\n1 HUSB @I4@\n1 CHIL @I6@\n"
            // A second record with the xref of the first is not read.
            . "0 @I1@ INDI\n1 BIRT\n2 DATE 2000\n1 DEAT\n2 DATE 1990\n"
            . "0 TRLR\n");

        self::assertSame(['KV5003 warning line 18 (@I4@): The individual is their own ancestor (a cycle of 2 individuals)'], $this->lines($doc));
    }

    #[Test]
    public function longPedigreeNeedsNoRecursion(): void
    {
        $generations = 20_000;
        $gedcom = self::HEAD_70;
        for ($i = 1; $i <= $generations; ++$i) {
            $gedcom .= "0 @I{$i}@ INDI\n" . ($i < $generations ? "1 FAMC @F{$i}@\n" : "1 FAMC @F0@\n") . ($i > 1 ? '1 FAMS @F' . ($i - 1) . "@\n" : '');
            $gedcom .= "0 @F{$i}@ FAM\n1 HUSB @I" . ($i + 1) . "@\n1 CHIL @I{$i}@\n";
        }
        $gedcom .= "0 @F0@ FAM\n1 HUSB @I1@\n0 TRLR\n";

        $lines = $this->lines(Gedcom::parse($gedcom));

        self::assertCount(1, $lines);
        self::assertStringStartsWith('KV5003 warning line 4 (@I1@): The individual is their own ancestor (a cycle of 20000 individuals)', $lines[0]);
    }

    /** @return list<string> */
    private function lines(\Korin\Gedcom\Syntax\Document $doc): array
    {
        $report = Gedcom::validate($doc, (new ValidationOptions())->withPlausibility());

        return array_values(array_map(strval(...), array_filter($report->diagnostics(), static fn(Diagnostic $d): bool => str_starts_with($d->code, 'KV5'))));
    }
}

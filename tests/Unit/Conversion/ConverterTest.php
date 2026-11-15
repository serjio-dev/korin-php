<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Converter;
use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    private const FILE_55 = "0 HEAD\r\n1 GEDC\r\n2 VERS 5.5\r\n2 FORM LINEAGE-LINKED\r\n1 CHAR ASCII\r\n0 @I1@ INDI\r\n1 NAME Anna /Koval/\r\n1 _MARK x\r\n0 TRLR\r\n";

    #[Test]
    public function stepsAreChainedAndTheOutputFollowsTheTarget(): void
    {
        $converter = new Converter([
            $this->step(Version::V5_5, Version::V5_5_1, $this->rule(static function (Document $d, int $record): void {
                if ($d->tagOf($record) === 'INDI') {
                    $d->createNode($record, null, 'NOTE', 'from 5.5 to ' . $d->version()->value);
                }
            })),
            $this->step(Version::V5_5_1, Version::V7_0, $this->rule(static function (Document $d, int $record): void {
                foreach ($d->childIds($record) as $child) {
                    if ($d->tagOf($child) === 'NOTE') {
                        $d->setPayload($child, $d->payloadOf($child) . ', then 5.5.1 to 7.0');
                    }
                    if ($d->tagOf($child) === 'CHAR' || $d->tagOf($child) === 'GEDC') {
                        foreach ($d->childIds($child) as $grandchild) {
                            if ($d->tagOf($grandchild) === 'FORM') {
                                $d->removeNode($grandchild);
                            }
                        }
                    }
                    if ($d->tagOf($child) === 'CHAR') {
                        $d->removeNode($child);
                    }
                }
            })),
        ]);
        $doc = Gedcom::parse(self::FILE_55);

        $result = $converter->convert($doc, Version::V7_0);

        self::assertSame(
            "\u{FEFF}0 HEAD\r\n1 GEDC\r\n2 VERS 7.0\r\n0 @I1@ INDI\r\n1 NAME Anna /Koval/\r\n1 _MARK x\r\n1 NOTE from 5.5 to 5.5, then 5.5.1 to 7.0\r\n0 TRLR\r\n",
            Gedcom::serialize($result->document()),
        );
        self::assertSame(Version::V7_0, $result->document()->version());
        self::assertTrue($result->losses()->isEmpty());
        self::assertSame(self::FILE_55, Gedcom::serialize($doc), 'the input is not changed');
        self::assertSame(Version::V5_5, $doc->version());
    }

    #[Test]
    public function downToFiveFiveIsWrittenInUtf16WithCharUnicode(): void
    {
        $converter = new Converter([$this->step(Version::V5_5_1, Version::V5_5)]);
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Анна /Коваль/\n0 TRLR\n");

        $bytes = Gedcom::serialize($converter->convert($doc, Version::V5_5)->document());

        self::assertSame("\xFE\xFF" . mb_convert_encoding("0 HEAD\n1 GEDC\n2 VERS 5.5\n2 FORM LINEAGE-LINKED\n1 CHAR UNICODE\n0 @I1@ INDI\n1 NAME Анна /Коваль/\n0 TRLR\n", 'UTF-16BE', 'UTF-8'), $bytes);
    }

    #[Test]
    public function theSameVersionIsACopy(): void
    {
        $doc = Gedcom::parse(self::FILE_55);

        $result = (new Converter([]))->convert($doc, Version::V5_5);

        self::assertNotSame($doc, $result->document());
        self::assertSame(self::FILE_55, Gedcom::serialize($result->document()));
        self::assertSame('{"from":"5.5","to":"5.5","entries":[]}', json_encode($result->losses()));
    }

    #[Test]
    public function aVersionWithoutARouteIsNotSupported(): void
    {
        $converter = new Converter([$this->step(Version::V5_5, Version::V5_5_1)]);

        self::assertTrue($converter->supports(Version::V5_5, Version::V5_5_1));
        self::assertFalse($converter->supports(Version::V5_5_1, Version::V5_5));
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Conversion from 5.5.1 to 5.5 is not supported yet');
        $converter->convert(Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n0 TRLR\n"), Version::V5_5);
    }

    #[Test]
    public function linesThatAreNotGedcomAreDroppedAndReported(): void
    {
        $converter = new Converter([$this->step(Version::V5_5, Version::V5_5_1)]);
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5\n1 CHAR ASCII\n0 @I1@ INDI\n1 NAME Anna\nnot a line\n\n0 TRLR\n");

        $result = $converter->convert($doc, Version::V5_5_1);

        self::assertSame("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Anna\n0 TRLR\n", Gedcom::serialize($result->document()));
        self::assertSame(
            ['KL1001 warning line 7 (@I1@/NAME), dropped: A line that is not a GEDCOM line is dropped'],
            array_map(strval(...), $result->losses()->entries()),
        );
        self::assertSame(
            '{"from":"5.5","to":"5.5.1","entries":[{"code":"KL1001","severity":"warning","path":"@I1@/NAME","line":7,"storedAs":"dropped","message":"A line that is not a GEDCOM line is dropped"}]}',
            json_encode($result->losses(), JSON_UNESCAPED_SLASHES),
        );
    }

    #[Test]
    public function lossesAreLocatedInTheSource(): void
    {
        $converter = new Converter([$this->step(Version::V5_5, Version::V5_5_1, $this->rule(static function (Document $d, int $record, Context $c): void {
            foreach ($d->childIds($record) as $child) {
                if ($d->tagOf($child) === '_MARK') {
                    $c->loss('KL9001', Severity::Info, $child, StoredAs::Dropped, 'dropped');
                    $d->removeNode($child);
                }
                if ($d->tagOf($child) === 'NAME') {
                    $new = $d->createNode($child, null, '_NEW', 'x');
                    $c->loss('KL9002', Severity::Warning, $new, StoredAs::Extension, 'added');
                }
            }
        }))]);

        $losses = $converter->convert(Gedcom::parse(self::FILE_55), Version::V5_5_1)->losses();

        self::assertSame([
            'KL9002 warning line 7 (@I1@/NAME), extension: added',
            'KL9001 info line 8 (@I1@/_MARK), dropped: dropped',
        ], array_map(strval(...), $losses->entries()));
    }

    #[Test]
    public function failPolicyStopsAtTheFirstLoss(): void
    {
        $converter = new Converter([$this->step(Version::V5_5, Version::V5_5_1, $this->rule(static function (Document $d, int $record, Context $c): void {
            if ($d->tagOf($record) === 'INDI') {
                $c->loss('KL9001', Severity::Warning, $record, StoredAs::Dropped, 'gone');
            }
        }))]);

        try {
            $converter->convert(Gedcom::parse(self::FILE_55), Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::Fail));
            self::fail('No exception');
        } catch (ConversionException $e) {
            self::assertInstanceOf(Loss::class, $e->loss);
            self::assertSame('@I1@', $e->loss->path);
        }
    }

    #[Test]
    public function newRecordsGoBeforeTrlrAndThroughTheRemainingSteps(): void
    {
        $prepass = new class implements Rule {
            public function needsPrepass(): bool
            {
                return true;
            }

            public function record(Document $document, int $record, Context $context): void
            {
                if ($document->tagOf($record) === 'INDI') {
                    $context->addRecord('NOTE', $context->newXref(), 'extracted');
                    $context->addRecord('NOTE', $context->newXref(), 'extracted too');
                }
            }
        };
        $converter = new Converter([
            $this->step(Version::V5_5, Version::V5_5_1, $prepass),
            $this->step(Version::V5_5_1, Version::V5_5_5, $this->rule(static function (Document $d, int $record): void {
                if ($d->tagOf($record) === 'NOTE') {
                    $d->setPayload($record, $d->payloadOf($record) . ' in 5.5.1');
                }
            })),
        ]);
        // @X1@ is taken, so the new xrefs are @X2@ and @X3@.
        $doc = Gedcom::parse(str_replace('0 TRLR', "0 @X1@ NOTE taken\n0 TRLR", self::FILE_55));

        $out = Gedcom::serialize($converter->convert($doc, Version::V5_5_5)->document());

        self::assertStringEndsWith("0 @X1@ NOTE taken in 5.5.1\r\n0 @X2@ NOTE extracted in 5.5.1\r\n0 @X3@ NOTE extracted too in 5.5.1\r\n0 TRLR\r\n", $out);
    }

    #[Test]
    public function recordsAddedByARuleThatRemovesItsRecordStillGoThroughTheRemainingSteps(): void
    {
        $converter = new Converter([
            self::step(Version::V5_5, Version::V5_5_1, self::rule(static function (Document $d, int $record, Context $c): void {
                if ($d->tagOf($record) === 'INDI') {
                    $c->addRecord('NOTE', '@N' . trim((string) $d->xrefOf($record), '@') . '@', "@first\nsecond");
                    $d->removeNode($record);
                }
            })),
            self::step(Version::V5_5_1, Version::V5_5_5, self::rule(static function (Document $d, int $record): void {
                if ($d->tagOf($record) === 'NOTE') {
                    $d->createNode($record, null, '_SEEN', '5.5.1');
                }
            })),
        ]);
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5\n1 CHAR ASCII\n2 VERS 1.0\n0 @I1@ INDI\n0 @I2@ INDI\n0 TRLR\n");

        $out = Gedcom::serialize($converter->convert($doc, Version::V5_5_5)->document());

        // The value is escaped for the step's target; the old CHAR loses its VERS.
        self::assertSame(
            "\u{FEFF}0 HEAD\n1 GEDC\n2 VERS 5.5.5\n1 CHAR UTF-8\n0 @NI1@ NOTE @@first\n1 CONT second\n1 _SEEN 5.5.1\n0 @NI2@ NOTE @@first\n1 CONT second\n1 _SEEN 5.5.1\n0 TRLR\n",
            $out,
        );
    }

    #[Test]
    public function undecodableBytesAreReportedAndLossesComeInDocumentOrder(): void
    {
        $converter = new Converter([self::step(Version::V5_5_1, Version::V5_5_5)]);
        $doc = Gedcom::parse("0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n0 @I1@ INDI\nfirst stray\n1 NAME An\xFFna\nsecond stray\n0 TRLR\n", (new ReadOptions())->withEncoding(Encoding::Utf8));

        $losses = $converter->convert($doc, Version::V5_5_5)->losses();

        self::assertSame([
            'KL1001 warning line 6 (@I1@), dropped: A line that is not a GEDCOM line is dropped',
            'KL1002 warning line 7 (@I1@/NAME), dropped: Bytes invalid in the source encoding are kept as U+FFFD',
            'KL1001 warning line 8 (@I1@/NAME), dropped: A line that is not a GEDCOM line is dropped',
        ], array_map(strval(...), $losses->entries()));
    }

    #[Test]
    public function newXrefsNeedThePrepass(): void
    {
        $converter = new Converter([$this->step(Version::V5_5, Version::V5_5_1, $this->rule(static function (Document $d, int $record, Context $c): void {
            $c->newXref();
        }))]);

        $this->expectException(\LogicException::class);
        $converter->convert(Gedcom::parse(self::FILE_55), Version::V5_5_1);
    }

    private function step(Version $from, Version $to, Rule ...$rules): Step
    {
        return new class ($from, $to, array_values($rules)) implements Step {
            /** @param list<Rule> $rules */
            public function __construct(private readonly Version $from, private readonly Version $to, private readonly array $rules) {}

            public function from(): Version
            {
                return $this->from;
            }

            public function to(): Version
            {
                return $this->to;
            }

            public function rules(): array
            {
                return $this->rules;
            }
        };
    }

    /** @param \Closure(Document, int, Context): void $apply */
    private function rule(\Closure $apply): Rule
    {
        return new class ($apply) implements Rule {
            /** @param \Closure(Document, int, Context): void $apply */
            public function __construct(private readonly \Closure $apply) {}

            public function needsPrepass(): bool
            {
                return false;
            }

            public function record(Document $document, int $record, Context $context): void
            {
                ($this->apply)($document, $record, $context);
            }
        };
    }
}

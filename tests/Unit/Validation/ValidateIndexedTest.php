<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Validation;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Validation\ValidationOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidateIndexedTest extends TestCase
{
    private const HEAD_551 = "0 HEAD\n1 SOUR Test\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n1 SUBM @U1@\n0 @U1@ SUBM\n1 NAME U\n";

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        yield 'duplicate xrefs, the second one pointed to' => [self::HEAD_551 . "0 @I1@ INDI\n1 FAMS @F1@\n0 @F1@ FAM\n1 HUSB @I1@\n0 @I1@ INDI\n1 FAMC @F1@\n0 @F1@ NOTE x\n0 TRLR\n"];
        yield 'links mirrored, not mirrored, to the wrong type, dangling' => [self::HEAD_551 . "0 @F1@ FAM\n1 HUSB @I1@\n1 WIFE @I2@\n1 CHIL @I3@\n1 CHIL @N1@\n0 @I1@ INDI\n1 FAMS @F1@\n0 @I2@ INDI\n1 FAMC @F1@\n0 @I3@ INDI\n1 FAMC @F1@\n1 FAMS @F2@\n1 FAMC @F9@\n0 @N1@ NOTE n\n0 @F2@ FAM\n1 WIFE @I3@\n1 HUSB @I3@\n0 TRLR\n"];
        yield 'a child pointing to its family before it' => [self::HEAD_551 . "0 @I1@ INDI\n1 FAMC @F1@\n0 @F1@ FAM\n1 CHIL @I1@\n1 CHIL @I2@\n0 @I2@ INDI\n0 TRLR\n"];
        yield 'births after deaths, children before parents, cycles' => [self::HEAD_551 . "0 @I1@ INDI\n1 BIRT\n2 DATE 1900\n1 DEAT\n2 DATE 1890\n1 FAMC @F2@\n1 FAMS @F1@\n0 @I2@ INDI\n1 BIRT\n2 DATE ABT 1950\n1 BIRT\n2 DATE 1800\n1 FAMC @F1@\n1 FAMS @F2@\n0 @F1@ FAM\n1 HUSB @I1@\n1 CHIL @I2@\n1 CHIL @I3@\n0 @F2@ FAM\n1 WIFE @I2@\n1 CHIL @I1@\n0 @I3@ INDI\n1 BIRT\n2 DATE 1700\n1 FAMC @I3@\n0 @I4@ INDI\n1 FAMC @F3@\n0 @F3@ FAM\n1 HUSB @I4@\n1 CHIL @I4@\n0 TRLR\n"];
        yield 'structure, values and lines that are not GEDCOM lines' => [self::HEAD_551 . "0 @I1@ INDI\n1 SEX Q\n1 FOO bar\nnot a line\n1 NAME A\n2 GIVN\n0 @R1@ REPO\n0 BAR\n0 TRLR\n0 @I9@ INDI\n"];
        yield '5.5.5 SUBM not after HEAD, no BOM' => ["0 HEAD\n1 GEDC\n2 VERS 5.5.5\n2 FORM LINEAGE-LINKED\n3 VERS 5.5.5\n1 CHAR UTF-8\n1 SUBM @U1@\n0 @I1@ INDI\n0 @U1@ SUBM\n1 NAME U\n0 TRLR\n"];
        yield '7.0, void pointers and extensions' => ["\xEF\xBB\xBF0 HEAD\n1 GEDC\n2 VERS 7.0\n0 @I1@ INDI\n1 FAMC @VOID@\n1 _LINK @X1@\n1 ASSO @I2@\n2 ROLE FRIEND\n0 TRLR\n"];
        // Found by fuzzing: an ANSEL combining mark goes with the character after it, here a space.
        yield 'ANSEL marks on delimiters' => ["0 HEAD\r\n1 CHAR ANSEL\r\n1 GEDC\r\n2 VERS 5.5.1\r\n0 @U1@ SUBM\r\n0\xE9 @1@ INDI\r\n1 FAMS @F1@\r\n0 @I1@ \xF7INDI\r\n0 @I1@ \xF7INDI\r\n0 TRLR\r\n"];
        // Found by fuzzing: diagnostics of one line came in another order (encoding before syntax).
        yield 'CHAR as a record' => ["x\n1 CHAR WHATEVER\n0 TRLR\n"];
        yield 'no HEAD' => ["0 @I1@ INDI\n1 NAME A\n0 TRLR\n"];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('files')]
    public function diagnosticsAreThoseOfTheDocument(string $bytes): void
    {
        foreach ([new ValidationOptions(), (new ValidationOptions())->withPlausibility(), (new ValidationOptions())->withDisabled('KV4004')] as $options) {
            $expected = array_map(strval(...), Gedcom::validate(Gedcom::parse($bytes), $options)->diagnostics());
            foreach ([0, 1000] as $cacheSize) {
                $document = new IndexedDocument($this->handle($bytes), cacheSize: $cacheSize);

                self::assertSame($expected, array_map(strval(...), Gedcom::validate($document, $options)->diagnostics()), "Cache of {$cacheSize}");
            }
        }
    }

    #[Test]
    public function validatingTwiceGivesTheSameReport(): void
    {
        $document = new IndexedDocument($this->handle(self::HEAD_551 . "0 @I1@ INDI\n1 FAMC @F1@\n0 @F1@ FAM\n0 TRLR\n"));
        $validator = new \Korin\Gedcom\Validation\Validator();
        $options = (new ValidationOptions())->withPlausibility();

        self::assertEquals($validator->validate($document, $options), $validator->validate($document, $options));
        self::assertCount(1, $validator->validate($document, $options)->diagnostics());
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

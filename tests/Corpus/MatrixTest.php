<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Corpus;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * SPEC section 10, test type 4: every corpus input converted to every version (the conversion
 * matrix). The output validates without errors, other than those the input has (by code) and
 * the documented limits of the target (docs/conversion.md); the conversion is deterministic,
 * and converting the output to its own version changes nothing. The loss reports of the cells
 * a case names are checked against its golden files by CorpusTest.
 */
final class MatrixTest extends TestCase
{
    /**
     * Errors a valid input may still give, by target: code and a pattern of the message.
     * 5.x requires a submitter a 7.0 file need not name; 5.x lists few media formats (FORM png
     * stays); a 5.5 media record holds the object itself (BLOB), not a file; 7.0 and 5.5.5
     * require a TYPE that FACT and IDNO need not have in 5.5.1.
     */
    private const LIMITS = [
        '5.5' => [['KV2003', '/^HEAD requires SUBM$/'], ['KV3006', '/^FORM does not permit/'], ['KV2003', '/^OBJE requires BLOB$/']],
        '5.5.1' => [['KV2003', '/^HEAD requires SUBM$/'], ['KV3006', '/^FORM does not permit/']],
        '5.5.5' => [['KV2003', '/^The file requires a SUBM record$/'], ['KV3006', '/^FORM does not permit/'], ['KV2003', '/^(FACT|IDNO) requires TYPE$/']],
        '7.0' => [['KV2003', '/^(FACT|IDNO) requires TYPE$/']],
    ];

    /** @return iterable<string, array{string, Version}> */
    public static function cells(): iterable
    {
        $root = getenv('KORIN_CORPUS');
        if ($root === false || $root === '') {
            $root = __DIR__ . '/../../vendor/korin/gedcom-corpus';
        }
        $files = glob($root . '/cases/*/case.yaml');
        foreach ($files === false ? [] : $files as $file) {
            /** @var array{input: array{file: string}} $case */
            $case = Yaml::parseFile($file);
            if (str_ends_with($case['input']['file'], '.ged')) {
                foreach (Version::cases() as $to) {
                    yield basename(dirname($file)) . ' to ' . $to->value => [dirname($file) . '/' . $case['input']['file'], $to];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('cells')]
    public function theOutputIsValidDeterministicAndStable(string $file, Version $to): void
    {
        $input = Gedcom::read($file);
        $result = Gedcom::convert($input, $to);
        $again = Gedcom::convert(Gedcom::read($file), $to);

        self::assertSame($result->output(), $again->output(), 'Deterministic output');
        self::assertSame(array_map(strval(...), $result->losses()->entries()), array_map(strval(...), $again->losses()->entries()), 'Deterministic loss report');
        self::assertSame($result->output(), Gedcom::convert($result->document(), $to)->output(), 'Converting to its own version changes nothing');
        $out = fopen('php://memory', 'w+b');
        self::assertNotFalse($out);
        $losses = Gedcom::convertFile($file, $out, $to);
        rewind($out);
        self::assertSame($result->output(), stream_get_contents($out), 'convertFile() writes the output of convert()');
        self::assertSame(json_encode($result->losses()), json_encode($losses), 'convertFile() reports the losses of convert()');

        $before = [];
        foreach (Gedcom::validate($input)->errors() as $error) {
            $before[$error->code] = true;
        }
        $unexpected = array_filter(Gedcom::validate($result->document())->errors(), fn(Diagnostic $error): bool => !isset($before[$error->code]) && !$this->isLimit($to, $error));
        self::assertSame([], array_values(array_map(strval(...), $unexpected)), 'The output must validate without errors');
    }

    private function isLimit(Version $to, Diagnostic $error): bool
    {
        foreach (self::LIMITS[$to->value] as [$code, $pattern]) {
            if ($error->code === $code && preg_match($pattern, $error->message) === 1) {
                return true;
            }
        }

        return false;
    }
}

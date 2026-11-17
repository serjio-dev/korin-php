<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Corpus;

use Korin\Gedcom\Conversion\Converter;
use Korin\Gedcom\Conversion\Loss;
use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Tests\Support\Fingerprint;
use Korin\Gedcom\Tests\Support\Ops;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs gedcom-corpus cases. The corpus comes from vendor/korin/gedcom-corpus, or from
 * KORIN_CORPUS (a path to a local checkout) when set. Only checks implemented so far run, and
 * convert only for the conversions the library supports so far.
 */
final class CorpusTest extends TestCase
{
    private const IMPLEMENTED = ['read', 'roundtrip-preserve', 'roundtrip-stream', 'fingerprint', 'validate', 'edit', 'convert'];

    /** @return iterable<string, array{string, string, array<string, mixed>, ?array{to: string, expected: string, losses: string}}> */
    public static function cases(): iterable
    {
        $root = getenv('KORIN_CORPUS');
        if ($root === false || $root === '') {
            $root = __DIR__ . '/../../vendor/korin/gedcom-corpus';
        }
        $dirs = glob($root . '/cases/*', GLOB_ONLYDIR);
        if ($dirs === false || $dirs === []) {
            throw new \RuntimeException("No corpus cases under {$root}; run composer install or set KORIN_CORPUS");
        }
        foreach ($dirs as $dir) {
            /** @var array<string, mixed> $case */
            $case = Yaml::parseFile($dir . '/case.yaml');
            /** @var list<string> $checks */
            $checks = $case['checks'];
            foreach (array_intersect($checks, self::IMPLEMENTED) as $check) {
                if ($check !== 'convert') {
                    yield basename($dir) . ' ' . $check => [$dir, $check, $case, null];
                    continue;
                }
                /** @var array{version: string} $input */
                $input = $case['input'];
                /** @var list<array{to: string, expected: string, losses: string}> $conversions */
                $conversions = $case['convert'];
                foreach ($conversions as $conversion) {
                    if ((new Converter())->supports(Version::from($input['version']), Version::from($conversion['to']))) {
                        yield basename($dir) . " convert {$conversion['to']}" => [$dir, $check, $case, $conversion];
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param ?array{to: string, expected: string, losses: string} $conversion
     */
    #[Test]
    #[DataProvider('cases')]
    public function corpusCase(string $dir, string $check, array $case, ?array $conversion): void
    {
        /** @var array{file: string, version: string, encoding: string, bom?: bool} $input */
        $input = $case['input'];
        $bytes = (string) file_get_contents($dir . '/' . $input['file']);
        $document = Gedcom::parse($bytes);

        match ($check) {
            'read' => $this->checkRead($document, $bytes, $case),
            'roundtrip-preserve' => self::assertSame($bytes, Gedcom::serialize($document), 'Preserve mode must reproduce the input byte for byte'),
            'roundtrip-stream' => $this->checkStream($dir . '/' . $input['file'], $bytes, $document),
            'fingerprint' => self::assertEquals(json_decode((string) file_get_contents($dir . '/fingerprint.json'), true), Fingerprint::of($document)),
            'validate' => $this->checkValidate($document, $case),
            'edit' => $this->checkEdit($document, $dir),
            'convert' => $this->checkConvert($document, $dir, $conversion ?? self::fail('No conversion')),
            default => self::fail("Check {$check} is not implemented"),
        };
    }

    /** @param array<string, mixed> $case */
    private function checkRead(\Korin\Gedcom\Syntax\Document $document, string $bytes, array $case): void
    {
        /** @var array{version: string, encoding: string, bom?: bool} $input */
        $input = $case['input'];
        self::assertSame($input['version'], $document->version()->value, 'Version');
        self::assertSame($input['bom'] ?? false, $document->hasBom(), 'BOM');
        $asciiOnly = preg_match('/[\x80-\xFF\x00]/', $bytes) !== 1;
        if (!$asciiOnly) {
            self::assertSame($input['encoding'], $document->encoding()->value, 'Encoding');
        }

        /** @var array{recordCount?: int, diagnostics?: list<array{severity: string, path?: string, code?: string}>} $expect */
        $expect = $case['expect'] ?? [];
        if (isset($expect['recordCount'])) {
            $records = array_filter($document->records(), static fn(\Korin\Gedcom\Syntax\Node $r): bool => !in_array($r->tag(), ['HEAD', 'TRLR'], true));
            self::assertCount($expect['recordCount'], $records, 'Record count');
        }
        if (!isset($expect['diagnostics'])) {
            return;
        }
        $key = static fn(string $severity, ?string $path): string => $severity . ' ' . ($path ?? '-');
        $expected = array_map(static fn(array $d): string => $key($d['severity'], $d['path'] ?? null), $expect['diagnostics']);
        $actual = array_map(
            static fn(Diagnostic $d): string => $key($d->severity->value, $d->path),
            array_values(array_filter($document->diagnostics(), static fn(Diagnostic $d): bool => $d->severity !== Severity::Info)),
        );
        /** @var list<string> $checks */
        $checks = $case['checks'];
        if (in_array('validate', $checks, true)) {
            // The rest of the expected diagnostics belong to the validator (milestone 0.2).
            self::assertSame([], array_values(array_diff($actual, $expected)), 'Unexpected reader diagnostics: ' . implode('; ', array_map(strval(...), $document->diagnostics())));
        } else {
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, 'Reader diagnostics: ' . implode('; ', array_map(strval(...), $document->diagnostics())));
        }
    }

    /**
     * Validator diagnostics, Info excluded, match expect.diagnostics by severity and path (and code when given).
     *
     * @param array<string, mixed> $case
     */
    private function checkValidate(\Korin\Gedcom\Syntax\Document $document, array $case): void
    {
        /** @var array{diagnostics?: list<array{severity: string, path?: string, code?: string}>} $expect */
        $expect = $case['expect'] ?? [];
        $report = Gedcom::validate($document);
        $diagnostics = array_values(array_filter($report->diagnostics(), static fn(Diagnostic $d): bool => $d->severity !== Severity::Info));
        $expected = $expect['diagnostics'] ?? [];
        $key = static fn(string $severity, ?string $path): string => $severity . ' ' . ($path ?? '-');
        $wanted = array_map(static fn(array $d): string => $key($d['severity'], $d['path'] ?? null), $expected);
        $actual = array_map(static fn(Diagnostic $d): string => $key($d->severity->value, $d->path), $diagnostics);
        sort($wanted);
        sort($actual);
        $summary = 'Validator diagnostics: ' . implode('; ', array_map(strval(...), $diagnostics));
        self::assertSame($wanted, $actual, $summary);
        foreach ($expected as $d) {
            if (isset($d['code'])) {
                $codes = array_map(static fn(Diagnostic $x): string => $x->code, array_filter($diagnostics, static fn(Diagnostic $x): bool => $x->path === ($d['path'] ?? null)));
                self::assertContains($d['code'], $codes, $summary);
            }
        }
    }

    /**
     * The output equals expected.<ver>.ged, validates without errors, and the loss report matches
     * losses.<ver>.json by code, path and storedAs.
     *
     * @param array{to: string, expected: string, losses: string} $conversion
     */
    private function checkConvert(\Korin\Gedcom\Syntax\Document $document, string $dir, array $conversion): void
    {
        $result = Gedcom::convert($document, Version::from($conversion['to']));
        self::assertSame((string) file_get_contents($dir . '/' . $conversion['expected']), $result->output(), 'Output');
        self::assertSame($result->output(), Gedcom::serialize($result->document()), 'The document read back writes the output');
        $errors = Gedcom::validate($result->document())->errors();
        self::assertSame([], array_map(strval(...), $errors), 'The output must validate without errors');

        /** @var array{entries: list<array{code: string, path: string, storedAs: string}>} $golden */
        $golden = json_decode((string) file_get_contents($dir . '/' . $conversion['losses']), true);
        $expected = array_map(static fn(array $e): string => "{$e['code']} {$e['path']} {$e['storedAs']}", $golden['entries']);
        $actual = array_map(static fn(Loss $l): string => "{$l->code} {$l->path} {$l->storedAs->value}", $result->losses()->entries());
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'Losses: ' . implode('; ', array_map(strval(...), $result->losses()->entries())));
    }

    /**
     * Each record of the stream written in preserve mode gives the input; the records hold the
     * lines and diagnostics of the whole document.
     */
    private function checkStream(string $path, string $bytes, \Korin\Gedcom\Syntax\Document $document): void
    {
        $stream = Gedcom::stream($path);
        $out = '';
        $diagnostics = [];
        $records = 0;
        foreach ($stream as $record) {
            ++$records;
            $out .= Gedcom::serialize($record->document());
            array_push($diagnostics, ...array_map(strval(...), $record->document()->diagnostics()));
        }
        self::assertSame($bytes, $out, 'Records written in preserve mode must reproduce the input byte for byte');
        self::assertCount($records, $document->records());
        array_push($diagnostics, ...array_map(strval(...), $stream->diagnostics()));
        $expected = array_map(strval(...), $document->diagnostics());
        sort($expected);
        sort($diagnostics);
        self::assertSame($expected, $diagnostics, 'Diagnostics');
    }

    private function checkEdit(\Korin\Gedcom\Syntax\Document $document, string $dir): void
    {
        /** @var array{ops: list<array<string, mixed>>} $ops */
        $ops = json_decode((string) file_get_contents($dir . '/ops.json'), true);
        Ops::apply($document, $ops['ops']);
        self::assertSame((string) file_get_contents($dir . '/expected.ged'), Gedcom::serialize($document));
    }
}

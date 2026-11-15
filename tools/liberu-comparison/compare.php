<?php

declare(strict_types=1);

/*
 * Baseline comparison with liberu-genealogy/php-gedcom (spec section 14, milestone 0.1).
 *
 *   php compare.php [report.md]
 *
 * For every corpus input both libraries read the file and write it back; the output is read
 * with Korin and its semantic fingerprint is compared with the input's. Then both read the
 * generated benchmark files, each in its own process so peak memory is measured separately.
 * The corpus comes from ../../vendor/korin/gedcom-corpus or KORIN_CORPUS.
 */

use Korin\Gedcom\Benchmarks\BenchFile;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Tests\Support\Fingerprint;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/vendor/autoload.php';

const LIBERU_VERSION = '4.2.0';

$argv = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];

if (($argv[1] ?? '') === '--measure') {
    measure($argv[2], $argv[3]);
    exit(0);
}

$corpus = getenv('KORIN_CORPUS') ?: __DIR__ . '/../../vendor/korin/gedcom-corpus';
$rows = [];
foreach (glob($corpus . '/cases/*/case.yaml') ?: [] as $yaml) {
    $case = Yaml::parseFile($yaml);
    $file = dirname($yaml) . '/' . $case['input']['file'];
    $bytes = (string) file_get_contents($file);
    $expected = leaves(Fingerprint::of(Gedcom::parse($bytes)));
    $rows[] = [
        'case' => $case['id'],
        'version' => $case['input']['version'],
        'encoding' => $case['input']['encoding'],
        'korin' => run(static fn(): string => Gedcom::serialize(Gedcom::parse($bytes)), $bytes, $expected),
        'liberu' => run(static function () use ($file): string {
            $gedcom = (new \Gedcom\Parser())->parse($file) ?? throw new RuntimeException('parse() returned null');

            return \Gedcom\Writer::convert($gedcom);
        }, $bytes, $expected),
    ];
}

$perf = [];
foreach ([[10, 'UTF-8'], [10, 'Windows-1251'], [50, 'UTF-8']] as [$mb, $charset]) {
    $path = BenchFile::path($mb, $charset);
    foreach (['korin', 'liberu'] as $library) {
        $output = shell_exec(sprintf('%s -d memory_limit=4G %s --measure %s %s 2>&1', PHP_BINARY, escapeshellarg(__FILE__), $library, escapeshellarg($path)));
        $perf["{$mb} MB {$charset}"][$library] = json_decode((string) $output, true) ?? ['error' => trim((string) $output)];
    }
}

$report = render($rows, $perf);
if (isset($argv[1])) {
    file_put_contents($argv[1], $report);
    fwrite(STDERR, "Written to {$argv[1]}\n");
} else {
    echo $report;
}

/**
 * Reads and writes with one library; warnings are counted, not fatal.
 *
 * @param list<string> $expected
 *
 * @return array{status: string, warnings: int, identical: bool, kept: int, total: int, lost: list<string>, error?: string}
 */
function run(Closure $roundTrip, string $bytes, array $expected): array
{
    $warnings = 0;
    set_error_handler(static function () use (&$warnings): bool {
        ++$warnings;

        return true;
    });
    try {
        $output = $roundTrip();
    } catch (Throwable $e) {
        return ['status' => 'error', 'warnings' => $warnings, 'identical' => false, 'kept' => 0, 'total' => count($expected), 'lost' => [], 'error' => $e::class . ': ' . mb_strimwidth($e->getMessage(), 0, 100, '…')];
    } finally {
        restore_error_handler();
    }
    $actual = leaves(Fingerprint::of(Gedcom::parse($output)));
    [$kept, $lost] = compare($expected, $actual);

    return ['status' => 'ok', 'warnings' => $warnings, 'identical' => $output === $bytes, 'kept' => $kept, 'total' => count($expected), 'lost' => $lost];
}

/**
 * Every structure as "RECORD/TAG/TAG=value": xrefs and positions are left out, so renumbering
 * and reordering are not counted as losses.
 *
 * @param array<string, mixed> $fingerprint
 *
 * @return list<string>
 */
function leaves(array $fingerprint): array
{
    $out = [];
    $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
        $path = $prefix === '' ? $node['tag'] : $prefix . '/' . $node['tag'];
        $out[] = $path . (isset($node['value']) ? '=' . $node['value'] : (isset($node['pointer']) ? '=→' : ''));
        foreach ($node['children'] ?? [] as $child) {
            $walk($child, $path);
        }
    };
    foreach ($fingerprint['records'] as $record) {
        $walk($record, '');
    }

    return $out;
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 *
 * @return array{int, list<string>} structures kept (as a multiset) and the tag paths of lost ones
 */
function compare(array $expected, array $actual): array
{
    $available = array_count_values($actual);
    $kept = 0;
    $lost = [];
    foreach ($expected as $leaf) {
        if (($available[$leaf] ?? 0) > 0) {
            --$available[$leaf];
            ++$kept;
        } else {
            $lost[] = explode('=', $leaf, 2)[0];
        }
    }

    return [$kept, array_values(array_unique($lost))];
}

function measure(string $library, string $path): void
{
    $start = hrtime(true);
    if ($library === 'korin') {
        $records = count(Gedcom::read($path)->records()) - 2;
    } else {
        $gedcom = (new \Gedcom\Parser())->parse($path);
        $records = $gedcom === null ? 0 : count($gedcom->getIndi()) + count($gedcom->getFam());
    }
    $read = (hrtime(true) - $start) / 1e9;
    echo json_encode(['seconds' => round($read, 2), 'peakMb' => (int) round(memory_get_peak_usage() / 1048576), 'records' => $records]);
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, array<string, array<string, mixed>>> $perf
 */
function render(array $rows, array $perf): string
{
    $sum = static fn(string $lib, Closure $f): int => array_sum(array_map(static fn(array $r): int => (int) $f($r[$lib]), $rows));
    $total = count($rows);
    $out = "# Korin and liberu-genealogy/php-gedcom\n\n"
        . 'Generated by `tools/liberu-comparison/compare.php` on ' . date('Y-m-d') . ' with PHP ' . PHP_VERSION
        . ', liberu-genealogy/php-gedcom ' . LIBERU_VERSION . ' and Korin ' . trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --short HEAD')) . ".\n\n"
        . "Each corpus input is read and written back by each library; the output is read with Korin and its\n"
        . "structures (tag path and value, xrefs and order ignored) are compared with the input's.\n\n"
        . "Notes:\n\n"
        . "- liberu documents support for GEDCOM 5.5.1 only; the 7.0 and UTF-16 cases are included for completeness.\n"
        . "- A structure under a record whose xref was not written back counts as lost: pointers to it no longer resolve.\n"
        . "- liberu writes a `Format: …` line before `HEAD`; Korin reads it as an unparsable line and ignores it.\n"
        . "- Performance: one run per file on the machine that generated the report, not a PHPBench measurement.\n\n"
        . "## Summary\n\n| | Korin | liberu |\n| --- | --- | --- |\n";
    foreach ([
        'Read and written without an exception' => static fn(array $r): bool => $r['status'] === 'ok',
        'Output identical to the input' => static fn(array $r): bool => $r['identical'],
        'All structures kept' => static fn(array $r): bool => $r['status'] === 'ok' && $r['kept'] === $r['total'],
    ] as $label => $test) {
        $out .= "| {$label} | " . $sum('korin', $test) . " of {$total} | " . $sum('liberu', $test) . " of {$total} |\n";
    }
    $structures = $sum('korin', static fn(array $r): int => $r['total']);
    $out .= '| Structures kept | ' . $sum('korin', static fn(array $r): int => $r['kept']) . " of {$structures} | "
        . $sum('liberu', static fn(array $r): int => $r['kept']) . " of {$structures} |\n"
        . '| PHP warnings raised | ' . $sum('korin', static fn(array $r): int => $r['warnings']) . ' | ' . $sum('liberu', static fn(array $r): int => $r['warnings']) . " |\n";

    $errors = [];
    foreach ($rows as $row) {
        if (isset($row['liberu']['error'])) {
            $errors[$row['liberu']['error']][] = $row['case'];
        }
    }
    if ($errors !== []) {
        $out .= "\n## liberu exceptions\n\n| Exception | Cases |\n| --- | --- |\n";
        uasort($errors, static fn(array $a, array $b): int => count($b) <=> count($a));
        foreach ($errors as $error => $cases) {
            $out .= '| `' . str_replace('|', '\\|', $error) . '` | ' . count($cases) . " |\n";
        }
    }

    $out .= "\n## Reading the benchmark files\n\nGenerated, fictional 5.5.1 files (`benchmarks/BenchFile.php`); full read, each in a separate process.\n\n"
        . "| File | Korin | liberu |\n| --- | --- | --- |\n";
    $cell = static fn(array $m): string => isset($m['error']) ? 'error: `' . mb_strimwidth(str_replace(["\n", '|'], [' ', '\\|'], (string) $m['error']), 0, 80, '…') . '`' : "{$m['seconds']} s, {$m['peakMb']} MB";
    foreach ($perf as $file => $libs) {
        $out .= "| {$file} | " . $cell($libs['korin']) . ' | ' . $cell($libs['liberu']) . " |\n";
    }

    $out .= "\n## Corpus cases\n\nStructures kept; for liberu, the tag paths it lost (first five).\n\n"
        . "| Case | Version | Encoding | Korin | liberu | Lost by liberu |\n| --- | --- | --- | --- | --- | --- |\n";
    $result = static fn(array $r): string => $r['status'] === 'error' ? 'exception' : "{$r['kept']}/{$r['total']}" . ($r['identical'] ? ', identical' : '');
    foreach ($rows as $row) {
        $lost = array_slice($row['liberu']['lost'], 0, 5);
        $out .= "| {$row['case']} | {$row['version']} | {$row['encoding']} | " . $result($row['korin']) . ' | ' . $result($row['liberu'])
            . ' | ' . ($lost === [] ? '' : '`' . implode('`, `', $lost) . '`') . " |\n";
    }

    return $out;
}

<?php

declare(strict_types=1);

/*
 * Writes a deterministic, fictional GEDCOM 5.5.1 file of about the requested size.
 * Usage: php bin/generate-bench-file.php <megabytes> <output.ged> [UTF-8|Windows-1251]
 */

use Korin\Gedcom\Benchmarks\BenchFile;

require __DIR__ . '/../vendor/autoload.php';

$argv = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($argv) < 3 || !is_numeric($argv[1])) {
    fwrite(STDERR, "Usage: php bin/generate-bench-file.php <megabytes> <output.ged> [UTF-8|Windows-1251]\n");
    exit(1);
}
$written = BenchFile::write((int) ((float) $argv[1] * 1024 * 1024), $argv[2], $argv[3] ?? 'UTF-8');
printf("%s: %.1f MB\n", $argv[2], $written / 1048576);

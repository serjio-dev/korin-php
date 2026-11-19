<?php

declare(strict_types=1);

/*
 * Checks the line coverage thresholds of docs/SPEC.md section 10 in a Clover report:
 * at least 95% of the statements of Io, Syntax, Conversion and Writer.
 * Usage: php bin/check-coverage.php build/coverage/clover.xml
 */

const THRESHOLD = 95.0;
const DIRECTORIES = ['Io', 'Syntax', 'Conversion', 'Writer'];

$file = $argv[1] ?? '';
$xml = is_file($file) ? simplexml_load_file($file) : false;
if ($xml === false) {
    fwrite(STDERR, "Usage: php bin/check-coverage.php <clover.xml>\n");
    exit(2);
}

$counts = array_fill_keys(DIRECTORIES, [0, 0]);
$files = $xml->xpath('//file');
foreach (is_array($files) ? $files : [] as $source) {
    $path = (string) $source['name'];
    $relative = substr($path, (int) strrpos($path, '/src/') + 5);
    $directory = explode('/', $relative)[0];
    if (!isset($counts[$directory])) {
        continue;
    }
    foreach ($source->line as $line) {
        if ((string) $line['type'] === 'stmt') {
            ++$counts[$directory][1];
            $counts[$directory][0] += (int) $line['count'] > 0 ? 1 : 0;
        }
    }
}

$failed = false;
foreach ($counts as $directory => [$covered, $total]) {
    $percent = $total === 0 ? 100.0 : 100 * $covered / $total;
    $ok = $percent >= THRESHOLD;
    $failed = $failed || !$ok;
    printf("%s %-10s %6.2f%% (%d/%d)\n", $ok ? 'ok  ' : 'FAIL', $directory, $percent, $covered, $total);
}
exit($failed ? 1 : 0);

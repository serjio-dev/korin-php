<?php

declare(strict_types=1);

/*
 * Seeds the fuzz corpora in build/fuzz from the inputs of gedcom-corpus.
 *
 * php fuzz/seed.php [path to gedcom-corpus]   (default: KORIN_CORPUS, else vendor/korin/gedcom-corpus)
 */

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Version;

require __DIR__ . '/../vendor/autoload.php';

$env = getenv('KORIN_CORPUS');
$corpus = $argv[1] ?? (is_string($env) && $env !== '' ? $env : __DIR__ . '/../vendor/korin/gedcom-corpus');
$inputs = glob("{$corpus}/cases/*/input.*");
$inputs = $inputs === false ? [] : $inputs;
if ($inputs === []) {
    fwrite(STDERR, "No inputs in {$corpus}/cases\n");
    exit(1);
}
$build = __DIR__ . '/../build/fuzz';
$seed = static function (string $target, string $bytes) use ($build): void {
    if (!is_dir("{$build}/{$target}")) {
        mkdir("{$build}/{$target}", 0o777, true);
    }
    file_put_contents("{$build}/{$target}/seed-" . md5($bytes) . '.txt', $bytes);
};
$versions = [Version::V5_5, Version::V5_5_1, Version::V5_5_5, Version::V7_0];
$encodings = Encoding::cases();

foreach ($inputs as $input) {
    $bytes = (string) file_get_contents($input);
    if (str_ends_with($input, '.gdz')) {
        if (strlen($bytes) <= 8192) {
            $seed('gedzip', $bytes);
        }

        continue;
    }
    if (strlen($bytes) <= 4096) {
        $seed('read', $bytes);
    }
    $document = Gedcom::parse($bytes);
    $seed('decode', chr((int) array_search($document->encoding(), $encodings, true)) . substr($bytes, 0, 1023));
    $version = chr((int) array_search($document->version()->readAs(), $versions, true));
    foreach ($document->walk() as $id) {
        $node = $document->node($id);
        if (in_array($node->tag(), ['DATE', 'AGE', 'NAME', 'LATI'], true) && $node->value() !== null) {
            $value = $node->tag() === 'LATI' ? $node->value() . ' ' . $node->parent()?->first('LONG')?->value() : $node->value();
            $seed('values', $version . substr($value, 0, 255));
        }
    }
}
foreach (['read', 'values', 'decode', 'gedzip'] as $target) {
    $files = glob("{$build}/{$target}/*");
    echo $target, ': ', $files === false ? 0 : count($files), " inputs\n";
}

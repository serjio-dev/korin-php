<?php

declare(strict_types=1);

/*
 * GEDZIP archives of any bytes: only GedzipException (and nothing at all for a valid archive).
 *
 * vendor/bin/php-fuzzer fuzz fuzz/gedzip.php build/fuzz/gedzip
 */

use Korin\Gedcom\Exception\GedzipException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use PhpFuzzer\Config;

require __DIR__ . '/../vendor/autoload.php';

$path = tempnam(sys_get_temp_dir(), 'korin-fuzz-');
register_shutdown_function(static fn(): bool => @unlink($path));

/** @var Config $config */
$config->setMaxLen(8192);
$config->setAllowedExceptions([GedzipException::class]);
$config->setTarget(static function (string $input) use ($path): void {
    file_put_contents($path, $input);
    $archive = Gedcom::readGedzip($path, (new ReadOptions())->withArchiveLimits(1 << 20, 100));
    $missing = $archive->missing();
    if (array_values(array_unique($missing)) !== $missing) {
        throw new LogicException('A missing reference is listed twice');
    }
    foreach ($archive->entries() as $entry) {
        $stream = $archive->media(implode('/', array_map(rawurlencode(...), explode('/', $entry))));
        if (is_resource($stream)) {
            // A damaged entry warns as it is read (CRC, zlib errors): media streams are the archive's.
            @stream_get_contents($stream, 1 << 16);
            fclose($stream);
        }
    }
});

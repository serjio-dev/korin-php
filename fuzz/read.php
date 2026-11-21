<?php

declare(strict_types=1);

/*
 * Reading, validation, writing and conversion of any bytes. Besides crashes and exceptions that
 * are not documented, these break the rules of the library and are reported as crashes:
 * - preserve mode does not give the input back, byte for byte;
 * - stream() or open() + validate() do not find what read() + validate() finds;
 * - a conversion output does not read back in its version.
 *
 * vendor/bin/php-fuzzer fuzz fuzz/read.php build/fuzz/read
 */

use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Exception\ParseException;
use Korin\Gedcom\Exception\UnencodableCharacterException;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Version;
use PhpFuzzer\Config;

require __DIR__ . '/../vendor/autoload.php';

/** @var Config $config */
$config->setMaxLen(4096);
$config->addDictionary(__DIR__ . '/gedcom.dict');
$config->setAllowedExceptions([]);
$config->setTarget(static function (string $input): void {
    $options = (new ReadOptions())->withLimits(maxDepth: 32, maxLineLength: 1024);
    try {
        $document = Gedcom::parse($input, $options);
    } catch (ParseException $e) {
        // Outside strict mode, only the limits stop reading.
        if (preg_match('/exceeds the (nesting )?limit/', $e->getMessage()) !== 1) {
            throw $e;
        }

        return;
    }
    if (Gedcom::serialize($document) !== $input) {
        throw new LogicException('Preserve mode changed the input');
    }
    $report = json_encode(Gedcom::validate($document), JSON_THROW_ON_ERROR);

    $handle = fopen('php://memory', 'w+b');
    if ($handle === false) {
        throw new LogicException('No memory stream');
    }
    fwrite($handle, $input);
    rewind($handle);
    if (json_encode(Gedcom::validate(Gedcom::open($handle, $options)), JSON_THROW_ON_ERROR) !== $report) {
        throw new LogicException('open() and read() validate differently');
    }
    rewind($handle);
    $streamed = '';
    $stream = Gedcom::stream($handle, $options);
    foreach ($stream as $record) {
        $streamed .= Gedcom::serialize($record->document());
    }
    fclose($handle);
    // The byte order mark is written with the first record: a file of only the mark has none (SPEC section 6).
    if ($streamed === '' && $stream->hasBom()) {
        $streamed = $stream->encoding()->bom();
    }
    if ($streamed !== $input) {
        throw new LogicException('stream() records do not add up to the input');
    }

    foreach ([Version::V5_5_1, Version::V7_0] as $version) {
        try {
            $output = Gedcom::convert($document, $version)->output();
        } catch (ConversionException|UnencodableCharacterException) {
            continue;
        }
        // Without the line limit: 7.0 joins CONC lines into longer ones.
        if (Gedcom::parse($output)->version() !== $version) {
            throw new LogicException("A conversion to {$version->value} does not read as {$version->value}");
        }
    }
});

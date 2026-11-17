<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Conversion\ConversionResult;
use Korin\Gedcom\Conversion\Converter;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Parser;
use Korin\Gedcom\Syntax\RecordStream;
use Korin\Gedcom\Validation\ValidationOptions;
use Korin\Gedcom\Validation\ValidationReport;
use Korin\Gedcom\Validation\Validator;
use Korin\Gedcom\Writer\Writer;

/** Entry point for common tasks; the classes behind it can be injected directly. */
final class Gedcom
{
    public static function read(string $path, ReadOptions $options = new ReadOptions()): Document
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new IoException("Cannot read {$path}");
        }

        return self::parse($bytes, $options);
    }

    /**
     * Reads a file one record at a time, in memory that does not grow with the file.
     *
     * @param string|resource $source a path, or an open handle read from its current position (not closed)
     */
    public static function stream(mixed $source, ReadOptions $options = new ReadOptions()): RecordStream
    {
        if (is_resource($source)) {
            return new RecordStream($source, $options);
        }
        if (!is_string($source)) {
            throw new \InvalidArgumentException('A path or a stream resource is expected');
        }
        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new IoException("Cannot read {$source}");
        }

        return new RecordStream($handle, $options, close: true);
    }

    public static function parse(string $bytes, ReadOptions $options = new ReadOptions()): Document
    {
        return (new Parser())->parse($bytes, $options);
    }

    /** Checks the document against the profile of its version; the document is not changed. */
    public static function validate(Document $document, ValidationOptions $options = new ValidationOptions()): ValidationReport
    {
        return (new Validator())->validate($document, $options);
    }

    /**
     * Converts the document to another version; the document is not changed.
     *
     * @throws ConversionException when the conversion is not supported, or at a loss under LossPolicy::Fail
     */
    public static function convert(Document $document, Version $to, ConvertOptions $options = new ConvertOptions()): ConversionResult
    {
        return (new Converter())->convert($document, $to, $options);
    }

    public static function write(Document $document, string $path, WriteOptions $options = new WriteOptions()): void
    {
        if (@file_put_contents($path, self::serialize($document, $options)) === false) {
            throw new IoException("Cannot write {$path}");
        }
    }

    public static function serialize(Document $document, WriteOptions $options = new WriteOptions()): string
    {
        return (new Writer())->serialize($document, $options);
    }
}

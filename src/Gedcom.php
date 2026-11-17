<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Conversion\ConversionResult;
use Korin\Gedcom\Conversion\Converter;
use Korin\Gedcom\Conversion\LossReport;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Exception\GedzipException;
use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Gedzip\Archive;
use Korin\Gedcom\Gedzip\GedzipReader;
use Korin\Gedcom\Gedzip\GedzipWriter;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\IndexedDocument;
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

        return new RecordStream(self::handle($source), $options, close: true);
    }

    /**
     * Opens a file for reading without its tree in memory: an index of the records, which are
     * read from the file when asked for. A stream that cannot seek is copied to php://temp first.
     *
     * @param string|resource $source a path, or an open handle read from its current position (not closed)
     */
    public static function open(mixed $source, ReadOptions $options = new ReadOptions()): IndexedDocument
    {
        if (is_resource($source)) {
            // A stream wrapper may claim to seek and not do it.
            $position = ftell($source);
            if (stream_get_meta_data($source)['seekable'] && $position !== false && @fseek($source, $position) === 0) {
                return new IndexedDocument($source, $options);
            }
            $copy = fopen('php://temp', 'w+b');
            if ($copy === false) {
                throw new IoException('Cannot create a temporary stream');
            }
            if (stream_copy_to_stream($source, $copy) === false) {
                fclose($copy);

                throw new IoException('Cannot copy the stream to a temporary stream');
            }
            rewind($copy);

            return new IndexedDocument($copy, $options, close: true);
        }

        return new IndexedDocument(self::handle($source), $options, close: true);
    }

    public static function parse(string $bytes, ReadOptions $options = new ReadOptions()): Document
    {
        return (new Parser())->parse($bytes, $options);
    }

    /**
     * Checks the document against the profile of its version; the document is not changed. An
     * indexed file ({@see self::open()}) is checked record by record, without its tree in memory.
     */
    public static function validate(Document|IndexedDocument $document, ValidationOptions $options = new ValidationOptions()): ValidationReport
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

    /**
     * Converts a file of any size record by record, writing the output as it goes: the same bytes
     * and losses as {@see self::convert()} of the whole document, in memory that holds an index
     * of the input rather than its tree.
     *
     * @param string|resource $input  a path, or an open handle read from its current position (not closed)
     * @param string|resource $output a path, or an open handle written from its current position (not closed)
     *
     * @throws ConversionException when the conversion is not supported, or at a loss under LossPolicy::Fail
     */
    public static function convertFile(mixed $input, mixed $output, Version $to, ConvertOptions $options = new ConvertOptions(), ReadOptions $read = new ReadOptions()): LossReport
    {
        $document = self::open($input, $read);
        if (is_resource($output)) {
            return (new Converter())->convertFile($document, $output, $to, $options);
        }
        if (!is_string($output)) {
            throw new \InvalidArgumentException('A path or a stream resource is expected');
        }
        $handle = @fopen($output, 'wb');
        if ($handle === false) {
            throw new IoException("Cannot write {$output}");
        }
        try {
            return (new Converter())->convertFile($document, $handle, $to, $options);
        } finally {
            fclose($handle);
        }
    }

    public static function write(Document $document, string $path, WriteOptions $options = new WriteOptions()): void
    {
        if (@file_put_contents($path, self::serialize($document, $options)) === false) {
            throw new IoException("Cannot write {$path}");
        }
    }

    /**
     * Reads a GEDZIP archive (.gdz): gedcom.ged as a document, the media as streams on demand.
     * Needs ext-zip.
     *
     * @throws GedzipException for no ZIP, no gedcom.ged or a limit of the options exceeded
     */
    public static function readGedzip(string $path, ReadOptions $options = new ReadOptions()): Archive
    {
        return (new GedzipReader())->read($path, $options);
    }

    /**
     * Writes a GEDZIP archive: a 7.0 document as gedcom.ged, and the media under the names its
     * FILE references use. Needs ext-zip.
     *
     * @param array<array-key, string|resource> $media entry name => a file to add, or a stream
     *
     * @throws GedzipException
     */
    public static function writeGedzip(Document $document, string $path, array $media = [], WriteOptions $options = new WriteOptions()): void
    {
        (new GedzipWriter())->write($document, $path, $media, $options);
    }

    public static function serialize(Document $document, WriteOptions $options = new WriteOptions()): string
    {
        return (new Writer())->serialize($document, $options);
    }

    /** @return resource */
    private static function handle(mixed $path): mixed
    {
        if (!is_string($path)) {
            throw new \InvalidArgumentException('A path or a stream resource is expected');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new IoException("Cannot read {$path}");
        }

        return $handle;
    }
}

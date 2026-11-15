<?php

declare(strict_types=1);

namespace Korin\Gedcom;

use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Parser;
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

    public static function parse(string $bytes, ReadOptions $options = new ReadOptions()): Document
    {
        return (new Parser())->parse($bytes, $options);
    }

    /** Checks the document against the profile of its version; the document is not changed. */
    public static function validate(Document $document, ValidationOptions $options = new ValidationOptions()): ValidationReport
    {
        return (new Validator())->validate($document, $options);
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

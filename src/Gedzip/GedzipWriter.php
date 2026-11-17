<?php

declare(strict_types=1);

namespace Korin\Gedcom\Gedzip;

use Korin\Gedcom\Exception\GedzipException;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;
use Korin\Gedcom\WriteOptions;
use Korin\Gedcom\Writer\Writer;

/**
 * Writes a GEDZIP archive: the document as gedcom.ged, first, and the media under the names
 * its FILE references use. The archive is written to a temporary file next to the target and
 * renamed when complete, so a failure leaves no half-written archive.
 */
final readonly class GedzipWriter
{
    public function __construct(private Writer $writer = new Writer()) {}

    /**
     * @param array<array-key, string|resource> $media entry name => a file to add, or a stream read from its position (a name
     *        of digits is an integer key)
     *
     * @throws GedzipException for a document that is not 7.0, an unsafe entry name, or a file that cannot be written
     */
    public function write(Document $document, string $path, array $media = [], WriteOptions $options = new WriteOptions()): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new GedzipException('GEDZIP needs the zip extension (ext-zip)');
        }
        if ($document->version() !== Version::V7_0) {
            throw new GedzipException("A GEDZIP archive holds GEDCOM 7.0, not {$document->version()->value}: convert the document first");
        }
        foreach (array_keys($media) as $name) {
            if (!EntryName::isSafe((string) $name) || $name === EntryName::DOCUMENT || str_ends_with((string) $name, '/')) {
                throw new GedzipException('Not a name for a media entry: a relative path without "..", "\\" or a drive, other than gedcom.ged');
            }
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $zip = new \ZipArchive();
        if ($zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new GedzipException('Cannot create the archive');
        }
        try {
            $zip->addFromString(EntryName::DOCUMENT, $this->writer->serialize($document, $options->withEncoding(Encoding::Utf8)));
            foreach ($media as $name => $source) {
                $added = is_string($source) ? is_file($source) && $zip->addFile($source, (string) $name) : $zip->addFromString((string) $name, (string) stream_get_contents($source));
                if (!$added) {
                    throw new GedzipException('Cannot add a media file to the archive');
                }
            }
            if (!$zip->close()) {
                throw new GedzipException('Cannot write the archive');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($temporary);
            throw $e;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new GedzipException('Cannot write the archive');
        }
    }
}

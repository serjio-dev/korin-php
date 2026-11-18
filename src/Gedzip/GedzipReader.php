<?php

declare(strict_types=1);

namespace Korin\Gedcom\Gedzip;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\GedzipException;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Parser;
use Korin\Gedcom\Version;

/**
 * Reads a GEDZIP archive (GEDCOM 7.0 section 3.6): gedcom.ged at its root, the media its FILE
 * references name. The archive is checked before anything is decompressed: the sizes of the
 * entries against the limits of {@see ReadOptions} (zip bombs), their names (zip-slip). The
 * size of gedcom.ged is checked again as it is read, as the directory can lie.
 */
final readonly class GedzipReader
{
    /** Entries smaller than this are not checked for their compression ratio: a page of spaces is fine. */
    private const RATIO_FROM = 1_048_576;

    private const SYMLINK = 0o120000;

    public function __construct(private Parser $parser = new Parser()) {}

    /** @throws GedzipException */
    public function read(string $path, ReadOptions $options = new ReadOptions()): Archive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new GedzipException('GEDZIP needs the zip extension (ext-zip)');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw new GedzipException('Not a ZIP archive, or a damaged one');
        }

        $entries = $ignored = $diagnostics = [];
        $total = 0;
        $documentSize = null;
        $documentCrc = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new GedzipException('Cannot read the directory of the archive');
            }
            $name = $stat['name'];
            $total += $stat['size'];
            if ($total > $options->maxArchiveSize) {
                throw new GedzipException("The archive holds more than {$options->maxArchiveSize} bytes uncompressed");
            }
            if ($stat['size'] > self::RATIO_FROM && $stat['size'] > $options->maxCompressionRatio * max(1, $stat['comp_size'])) {
                throw new GedzipException("An entry is compressed more than {$options->maxCompressionRatio} times");
            }
            if (!EntryName::isSafe($name)) {
                $ignored[] = $name;
                $diagnostics[] = new Diagnostic('KV1201', Severity::Warning, 'An entry with an unsafe name is ignored: ' . $this->fragment($name));
                continue;
            }
            $opsys = $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === \ZipArchive::OPSYS_UNIX && is_int($attributes) && (($attributes >> 16) & 0o170000) === self::SYMLINK) {
                $ignored[] = $name;
                $diagnostics[] = new Diagnostic('KV1202', Severity::Warning, 'A symbolic link is ignored: ' . $this->fragment($name));
                continue;
            }
            if ($name === EntryName::DOCUMENT) {
                $documentSize = $stat['size'];
                $documentCrc = $stat['crc'];
            } elseif (!str_ends_with($name, '/')) {
                $entries[] = $name;
            }
        }
        if ($documentSize === null) {
            throw new GedzipException('The archive has no gedcom.ged');
        }

        $document = $this->parser->parse($this->document($zip, $documentSize, $documentCrc), $options);
        if ($document->version() !== Version::V7_0) {
            $diagnostics[] = new Diagnostic('KV1203', Severity::Warning, "gedcom.ged of a GEDZIP archive is GEDCOM 7.0, not {$document->version()->value}");
        }
        $missing = [];
        foreach ($this->references($document) as [$reference, $node]) {
            $name = EntryName::ofReference($reference);
            if ($name === null || in_array($name, $entries, true) || in_array($reference, $missing, true)) {
                continue;
            }
            $missing[] = $reference;
            $line = $document->lineOf($node);
            $diagnostics[] = new Diagnostic('KV1204', Severity::Warning, 'FILE names no entry of the archive', $line > 0 ? $line : null, $document->node($node)->path());
        }

        return new Archive($zip, $document, $entries, $ignored, $missing, $diagnostics);
    }

    private function document(\ZipArchive $zip, int $size, int $crc): string
    {
        $stream = $zip->getStream(EntryName::DOCUMENT);
        if ($stream === false) {
            throw new GedzipException('Cannot read gedcom.ged');
        }
        // Damaged deflate data makes the zip stream warn; a wrong CRC it reports only on a read
        // after the end, so the CRC is checked here.
        $damage = null;
        set_error_handler(static function (int $level, string $message) use (&$damage): bool {
            $damage = preg_replace('/^stream_get_contents\(\): (Zip stream error: )?/', '', $message);

            return true;
        });
        try {
            $bytes = stream_get_contents($stream, $size + 1);
        } finally {
            restore_error_handler();
            fclose($stream);
        }
        if ($damage === null && is_string($bytes) && strlen($bytes) === $size && crc32($bytes) !== $crc) {
            $damage = 'CRC error';
        }
        if ($damage !== null) {
            throw new GedzipException("gedcom.ged is damaged: {$damage}");
        }
        if ($bytes === false || strlen($bytes) !== $size) {
            throw new GedzipException('gedcom.ged is not the size the archive says');
        }

        return $bytes;
    }

    /** @return list<array{string, int}> the FILE values of the OBJE records and their nodes */
    private function references(Document $document): array
    {
        $references = [];
        foreach ($document->records() as $record) {
            if ($record->tag() !== 'OBJE') {
                continue;
            }
            foreach ($record->all('FILE') as $file) {
                $value = $file->value();
                if ($value !== null) {
                    $references[] = [$value, $file->id()];
                }
            }
        }

        return $references;
    }

    /** An entry name in a message: short, as names can be anything. */
    private function fragment(string $name): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F]/', '?', $name);
        // Whole UTF-8 characters, or bytes when the name is not UTF-8.
        $short = preg_match('/^.{0,40}/su', $clean, $m) === 1 ? $m[0] : substr($clean, 0, 40);

        return '"' . $short . ($short === $clean ? '' : '…') . '"';
    }
}

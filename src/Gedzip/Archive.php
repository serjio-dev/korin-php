<?php

declare(strict_types=1);

namespace Korin\Gedcom\Gedzip;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\GedzipException;
use Korin\Gedcom\Syntax\Document;

/**
 * A GEDZIP archive that was read ({@see \Korin\Gedcom\Gedcom::readGedzip()}): its gedcom.ged as
 * a document, and its media, read from the archive when asked for, never extracted to disk.
 * Entries with unsafe names and symbolic links are ignored (KV1201, KV1202).
 */
final readonly class Archive
{
    /**
     * @internal use {@see \Korin\Gedcom\Gedcom::readGedzip()}
     *
     * @param list<string>     $entries     safe entries, in order, gedcom.ged and directories left out
     * @param list<string>     $ignored     entries that are not used
     * @param list<string>     $missing     relative FILE references without an entry, in document order
     * @param list<Diagnostic> $diagnostics of the archive: KV1201–KV1204
     */
    public function __construct(
        private \ZipArchive $zip,
        private Document $document,
        private array $entries,
        private array $ignored,
        private array $missing,
        private array $diagnostics,
    ) {}

    public function document(): Document
    {
        return $this->document;
    }

    /** @return list<string> the media entries: every safe entry but gedcom.ged, in order */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<string> entries that are not used: unsafe names (zip-slip) and symbolic links */
    public function ignored(): array
    {
        return $this->ignored;
    }

    /** @return list<string> FILE references that are relative paths and name no entry */
    public function missing(): array
    {
        return $this->missing;
    }

    /** @return list<Diagnostic> of the archive; the document has those of its lines */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Whether a FILE reference ("photos/anna%20k.jpg") names an entry. */
    public function has(string $reference): bool
    {
        $name = EntryName::ofReference($reference);

        return $name !== null && in_array($name, $this->entries, true);
    }

    /**
     * The content of the entry a FILE reference names, as a stream decompressed as it is read.
     *
     * @return resource
     *
     * @throws GedzipException when the reference names no entry
     */
    public function media(string $reference): mixed
    {
        $name = EntryName::ofReference($reference);
        if ($name === null || !in_array($name, $this->entries, true)) {
            throw new GedzipException('The archive has no entry for this reference');
        }
        $stream = $this->zip->getStream($name);
        if ($stream === false) {
            throw new GedzipException('Cannot read an entry of the archive');
        }

        return $stream;
    }
}

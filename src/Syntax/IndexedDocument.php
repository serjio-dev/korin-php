<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Extension\SourceSystem;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Model\Family;
use Korin\Gedcom\Model\Individual;
use Korin\Gedcom\Model\MediaObject;
use Korin\Gedcom\Model\RecordView;
use Korin\Gedcom\Model\Repository;
use Korin\Gedcom\Model\SharedNote;
use Korin\Gedcom\Model\Source;
use Korin\Gedcom\Model\Submitter;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Version;

/**
 * A file opened for reading without its tree in memory (SPEC section 6, `open()`): an index of
 * where each record starts, and records parsed from the file when they are asked for, the
 * last ones kept in a cache. Building the index parses no record but the first and HEAD.
 *
 * A record is a read-only document of its own with the nodes, line numbers and line
 * diagnostics {@see Parser} gives it in the whole file; {@see Document::copy()} of it can be
 * edited. The version is that of the first HEAD, for every record. {@see self::diagnostics()}
 * has those of the whole file (encoding, HEAD, TRLR, version); line diagnostics are on the
 * records; in strict mode every record is parsed while the index is built, so a file read()
 * rejects is rejected here too. {@see self::load()} reads the whole file into a {@see Document}.
 *
 * The document reads from the handle it was given whenever a record is asked for, seeking it:
 * the handle must stay open and is not for other reads meanwhile. A file that cannot seek
 * (an entry of a ZIP through zip://) is read on forward and opened again to go back, which
 * reads it from the start: records read in order cost one pass, a record behind the last one
 * read costs a pass up to it.
 */
final class IndexedDocument implements \Countable
{
    /** Bytes per record in {@see $index}: file offset (J), first line (N), tag id (N). */
    private const ENTRY = 16;

    private const CACHE_SIZE = 1000;

    /** Records packed: 16 bytes each. */
    private string $index = '';

    /** @var array<string, int> xref => the first record with it */
    private array $xrefs = [];

    /** @var list<string> */
    private array $tagNames = [];

    /** @var int<0, max> */
    private int $count = 0;

    private readonly int $start;

    private readonly int $end;

    private ?int $headIndex = null;

    private ?int $trailerIndex = null;

    private Version $version;

    private readonly Encoding $encoding;

    private readonly bool $bom;

    private readonly string $lineEnding;

    /** @var list<Diagnostic> */
    private readonly array $diagnostics;

    /** @var array<int, Document> record => its document, the most recently used last */
    private array $cache = [];

    /**
     * @internal use {@see \Korin\Gedcom\Gedcom::open()}
     *
     * @param resource    $handle seekable, read from its current position; or, with $path, opened from it
     * @param bool        $close  whether the document closes the handle when it is destroyed
     * @param string|null $path   the file of a handle that cannot seek, opened again to go back
     */
    public function __construct(
        private mixed $handle,
        private readonly ReadOptions $options = new ReadOptions(),
        private readonly bool $close = false,
        private readonly int $cacheSize = self::CACHE_SIZE,
        private readonly Parser $parser = new Parser(),
        private readonly ?string $path = null,
    ) {
        if ($path !== null && (!$close || ftell($handle) !== 0)) {
            throw new \InvalidArgumentException('A handle opened again from its path is owned by the document and read from the start');
        }
        $this->start = (int) ftell($handle);
        $reader = new RecordReader($handle, $options);
        $detection = $reader->detection();
        $this->encoding = $detection->encoding;
        $this->bom = $detection->hasBom;
        /** @var array<string, int> $tagIds */
        $tagIds = [];
        $first = $head = null;
        $afterTrailer = null;
        foreach ($reader->records() as [$bytes, $offset, $line, $xref, $tag]) {
            if ($options->strict) {
                // As read() does: the first error of any line throws now, not when its record is read.
                $this->parser->parseRecord($bytes, $this->encoding, $options->version ?? Version::V5_5_1, false, "\n", $line, $options);
            }
            $i = $this->count++;
            if (!isset($tagIds[$tag])) {
                $tagIds[$tag] = count($this->tagNames);
                $this->tagNames[] = $tag;
            }
            $this->index .= pack('JNN', $this->start + $offset, $line, $tagIds[$tag]);
            if ($xref !== null) {
                $this->xrefs[$xref] ??= $i;
            }
            if ($i === 0) {
                $first = [$bytes, $line];
            }
            if ($tag === 'HEAD' && $this->headIndex === null) {
                $this->headIndex = $i;
                $head = [$bytes, $line];
            }
            if ($tag === 'TRLR') {
                $this->trailerIndex ??= $i;
                $afterTrailer = false;
            } elseif ($afterTrailer === false) {
                $afterTrailer = $line;
            }
        }
        $this->end = (int) ftell($handle);
        $this->lineEnding = $reader->lineEnding();

        $this->version = $options->version ?? Version::V5_5_1;
        $headNode = $head === null ? null : $this->parse($head[0], $head[1], $this->headIndex === 0);
        if ($headNode !== null) {
            $this->version = $options->version ?? Parser::declaredVersion($headNode, $options->dialects) ?? $this->version;
            // The lines do not depend on the version, only their values.
            $headNode->document()->setVersion($this->version);
            $this->cache[(int) $this->headIndex] = $headNode->document();
        }
        $firstNode = $this->headIndex === 0 ? $headNode : ($first === null ? null : $this->parse($first[0], $first[1], true));
        $diagnostics = $detection->diagnostics;
        $this->parser->checkFile($firstNode, $this->trailerIndex !== null, $afterTrailer === false ? null : $afterTrailer, $headNode, $options, static function (Diagnostic $diagnostic) use (&$diagnostics): void {
            $diagnostics[] = $diagnostic;
        });
        $this->diagnostics = $diagnostics;
    }

    public function __destruct()
    {
        if ($this->close && is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function version(): Version
    {
        return $this->version;
    }

    public function encoding(): Encoding
    {
        return $this->encoding;
    }

    public function hasBom(): bool
    {
        return $this->bom;
    }

    /** Dominant line terminator of the file. */
    public function lineEnding(): string
    {
        return $this->lineEnding;
    }

    /** @return list<Diagnostic> encoding detection, HEAD, TRLR and version (KV1004–KV1008) */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Records, HEAD and TRLR included. */
    public function count(): int
    {
        return $this->count;
    }

    /** @return \Generator<int, Node> all records in order, read one after another (not cached) */
    public function records(): \Generator
    {
        for ($i = 0; $i < $this->count; ++$i) {
            yield $this->recordAt($i, cache: false);
        }
    }

    public function record(string $xref): ?Node
    {
        $i = $this->xrefs[$xref] ?? null;

        return $i === null ? null : $this->recordAt($i);
    }

    public function individual(string $xref): ?Individual
    {
        return RecordView::typed($this->record($xref), $this, Individual::class);
    }

    /** @return \Generator<int, Individual> the INDI records in order, read one after another */
    public function individuals(): \Generator
    {
        return $this->views(Individual::class);
    }

    public function family(string $xref): ?Family
    {
        return RecordView::typed($this->record($xref), $this, Family::class);
    }

    /** @return \Generator<int, Family> the FAM records in order, read one after another */
    public function families(): \Generator
    {
        return $this->views(Family::class);
    }

    public function source(string $xref): ?Source
    {
        return RecordView::typed($this->record($xref), $this, Source::class);
    }

    /** @return \Generator<int, Source> the SOUR records in order, read one after another */
    public function sources(): \Generator
    {
        return $this->views(Source::class);
    }

    public function repository(string $xref): ?Repository
    {
        return RecordView::typed($this->record($xref), $this, Repository::class);
    }

    /** @return \Generator<int, Repository> the REPO records in order, read one after another */
    public function repositories(): \Generator
    {
        return $this->views(Repository::class);
    }

    public function mediaObject(string $xref): ?MediaObject
    {
        return RecordView::typed($this->record($xref), $this, MediaObject::class);
    }

    /** @return \Generator<int, MediaObject> the OBJE records in order, read one after another */
    public function mediaObjects(): \Generator
    {
        return $this->views(MediaObject::class);
    }

    public function sharedNote(string $xref): ?SharedNote
    {
        return RecordView::typed($this->record($xref), $this, SharedNote::class);
    }

    /** @return \Generator<int, SharedNote> the note records (SNOTE in 7.0, NOTE in 5.x) in order, read one after another */
    public function sharedNotes(): \Generator
    {
        return $this->views(SharedNote::class);
    }

    public function submitter(string $xref): ?Submitter
    {
        return RecordView::typed($this->record($xref), $this, Submitter::class);
    }

    /** @return \Generator<int, Submitter> the SUBM records in order, read one after another */
    public function submitters(): \Generator
    {
        return $this->views(Submitter::class);
    }

    /** The program that wrote the file, from HEAD.SOUR; null without one. */
    public function sourceSystem(): ?SourceSystem
    {
        $head = $this->head();

        return $head === null ? null : SourceSystem::of($head);
    }

    public function head(): ?Node
    {
        return $this->headIndex === null ? null : $this->recordAt($this->headIndex);
    }

    /** As {@see Document::find()}. */
    public function find(string $path): ?Node
    {
        $record = Path::parse($path)->record;
        $i = match ($record) {
            'HEAD' => $this->headIndex,
            'TRLR' => $this->trailerIndex,
            default => $this->xrefs[$record] ?? null,
        };

        return $i === null ? null : $this->recordAt($i)->document()->find($path);
    }

    /** The whole file read into a document, as {@see \Korin\Gedcom\Gedcom::read()} gives it. */
    public function load(): Document
    {
        return $this->parser->parse($this->bytes($this->start, $this->end - $this->start), $this->options);
    }

    /**
     * @internal the record with this 0-based number
     *
     * @param bool $cache whether to keep it among the recently used records
     */
    public function recordAt(int $i, bool $cache = true): Node
    {
        if (isset($this->cache[$i])) {
            $document = $this->cache[$i];
            unset($this->cache[$i]);
            $this->cache[$i] = $document;

            return $document->records()[0];
        }
        $node = $this->parse($this->bytesAt($i), $this->entry($i)[1], $i === 0);
        if ($cache && $this->cacheSize > 0) {
            $this->cache[$i] = $node->document();
            if (count($this->cache) > $this->cacheSize) {
                unset($this->cache[array_key_first($this->cache)]);
            }
        }

        return $node;
    }

    /** @internal the bytes of the record with this number, as in the file */
    public function bytesAt(int $i): string
    {
        [$offset] = $this->entry($i);
        $next = $i + 1 < $this->count ? $this->entry($i + 1)[0] : $this->end;

        return $this->bytes($offset, $next - $offset);
    }

    /**
     * @internal writes the file as it is, BOM included
     *
     * @param resource $out
     */
    public function copyTo(mixed $out): void
    {
        $this->seek($this->start);
        $copied = stream_copy_to_stream($this->handle, $out, $this->end - $this->start);
        if ($copied !== $this->end - $this->start) {
            throw new IoException('Cannot copy the file');
        }
    }

    /** @internal tag of the record with this number, without reading it ('' for a line that is not a GEDCOM line) */
    public function tagAt(int $i): string
    {
        return $this->tagNames[$this->entry($i)[2]];
    }

    /** @internal 1-based line of the record with this number, without reading it */
    public function lineAt(int $i): int
    {
        return $this->entry($i)[1];
    }

    /**
     * @internal
     *
     * @return array<string, int> xref => number of the first record with it
     */
    public function xrefs(): array
    {
        return $this->xrefs;
    }

    /**
     * @template T of RecordView
     *
     * @param class-string<T> $class
     *
     * @return \Generator<int, T>
     */
    private function views(string $class): \Generator
    {
        $tag = $class::tagIn($this->version);
        for ($i = 0; $i < $this->count; ++$i) {
            if ($this->tagAt($i) === $tag) {
                $view = RecordView::typed($this->recordAt($i, cache: false), $this, $class);
                if ($view !== null) {
                    yield $view;
                }
            }
        }
    }

    /** @return array{int, int, int} offset, line, tag id */
    private function entry(int $i): array
    {
        if ($i < 0 || $i >= $this->count) {
            throw new \OutOfRangeException("No record {$i}");
        }
        /** @var array{offset: int, line: int, tag: int} $entry */
        $entry = unpack('Joffset/Nline/Ntag', $this->index, $i * self::ENTRY);

        return [$entry['offset'], $entry['line'], $entry['tag']];
    }

    private function parse(string $bytes, int $line, bool $first): Node
    {
        $document = $this->parser->parseRecord($bytes, $this->encoding, $this->version, $this->bom && $first, $this->lineEnding, $line, $this->options);
        $document->freeze();

        return $document->records()[0];
    }

    private function bytes(int $offset, int $length): string
    {
        $this->seek($offset);
        $bytes = '';
        while (($remaining = $length - strlen($bytes)) > 0 && !feof($this->handle)) {
            $chunk = fread($this->handle, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        if (strlen($bytes) !== $length) {
            throw new IoException('The file changed after it was opened');
        }

        return $bytes;
    }

    private function seek(int $offset): void
    {
        if (!is_resource($this->handle)) {
            throw new IoException('Cannot seek in the file');
        }
        if ($this->path === null) {
            if (fseek($this->handle, $offset) !== 0) {
                throw new IoException('Cannot seek in the file');
            }

            return;
        }
        $position = ftell($this->handle);
        if ($position === false || $position > $offset) {
            $handle = @fopen($this->path, 'rb');
            if ($handle === false) {
                throw new IoException('Cannot open the file again');
            }
            fclose($this->handle);
            $this->handle = $handle;
            $position = 0;
        }
        for ($skip = $offset - $position; $skip > 0; $skip -= strlen($chunk)) {
            $chunk = fread($this->handle, min($skip, 65_536));
            if ($chunk === false || $chunk === '') {
                throw new IoException('The file changed after it was opened');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Version;

/**
 * A file read one {@see Record} at a time, in memory that does not grow with the file
 * (SPEC section 6, read modes). Records are parsed as {@see Parser} parses the whole file:
 * the same nodes, values and line diagnostics, which each record's document holds. The
 * encoding is detected from the BOM, HEAD.CHAR and the first 64 KB; bytes later in the file
 * that are invalid in it are reported on their line (KV1003), not decoded again.
 *
 * The version comes from the first HEAD: records before it are read as 5.5.1 (or the version
 * in the options). {@see self::diagnostics()} has those of the whole file (encoding, HEAD,
 * TRLR, version), complete once every record was read. A stream is read once.
 *
 * @implements \IteratorAggregate<int, Record>
 */
final class RecordStream implements \IteratorAggregate
{
    private readonly RecordReader $reader;

    private bool $started = false;

    private Version $version;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /**
     * @internal use {@see \Korin\Gedcom\Gedcom::stream()}
     *
     * @param resource $handle read from its current position
     * @param bool     $close  whether the stream closes the handle when it ends
     */
    public function __construct(
        private readonly mixed $handle,
        private readonly ReadOptions $options = new ReadOptions(),
        private readonly bool $close = false,
        int $chunkSize = 65536,
        private readonly Parser $parser = new Parser(),
    ) {
        $this->reader = new RecordReader($handle, $options, $chunkSize);
        $this->version = $options->version ?? Version::V5_5_1;
    }

    public function encoding(): Encoding
    {
        return $this->reader->detection()->encoding;
    }

    public function hasBom(): bool
    {
        return $this->reader->detection()->hasBom;
    }

    /** The version of the records read so far: 5.5.1 (or the one in the options) until HEAD is read. */
    public function version(): Version
    {
        return $this->version;
    }

    /**
     * @return list<Diagnostic> diagnostics of the whole file: encoding detection, then, once the
     *                          last record was read, HEAD, TRLR and version (KV1004–KV1008)
     */
    public function diagnostics(): array
    {
        return [...$this->reader->detection()->diagnostics, ...$this->diagnostics];
    }

    /** @return \Generator<int, Record> */
    public function getIterator(): \Generator
    {
        if ($this->started) {
            throw new \LogicException('A record stream can be read only once');
        }
        $this->started = true;
        try {
            yield from $this->records();
        } finally {
            if ($this->close && is_resource($this->handle)) {
                fclose($this->handle);
            }
        }
    }

    /** @return \Generator<int, Record> */
    private function records(): \Generator
    {
        $detection = $this->reader->detection();
        $first = $head = null;
        $hasTrailer = false;
        $afterTrailer = null;
        foreach ($this->reader->records() as [$bytes, , $line, , $tag]) {
            $document = $this->parser->parseRecord($bytes, $detection->encoding, $this->version, $detection->hasBom && $first === null, $this->reader->lineEnding(), $line, $this->options);
            $record = new Record($document);
            $node = $record->node();
            $first ??= $node;
            if ($tag === 'HEAD' && $head === null) {
                $head = $node;
                $version = $this->options->version === null ? Parser::declaredVersion($node) : null;
                if ($version !== null) {
                    $this->version = $version;
                    $document->setVersion($version);
                }
            }
            if ($tag === 'TRLR') {
                $hasTrailer = true;
                $afterTrailer = null;
            } elseif ($hasTrailer) {
                $afterTrailer ??= $line;
            }

            yield $record;
        }

        $this->parser->checkFile($first, $hasTrailer, $afterTrailer, $head, $this->options, function (Diagnostic $diagnostic): void {
            $this->diagnostics[] = $diagnostic;
        });
    }
}

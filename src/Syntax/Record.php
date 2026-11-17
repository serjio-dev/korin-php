<?php

declare(strict_types=1);

namespace Korin\Gedcom\Syntax;

use Korin\Gedcom\Model\Individual;
use Korin\Gedcom\Model\RecordView;

/**
 * One record of a {@see RecordStream}: a document of its own whose only record it is, so
 * nothing else of the file is in memory. Lines keep the numbers they have in the file;
 * writing the document in preserve mode gives the record's original bytes (the first record
 * of a file with a BOM starts with it).
 */
final readonly class Record
{
    /** @internal */
    public function __construct(private Document $document) {}

    public function document(): Document
    {
        return $this->document;
    }

    public function node(): Node
    {
        return $this->document->records()[0];
    }

    /** Empty for a line before the first record that is not a GEDCOM line. */
    public function tag(): string
    {
        return $this->node()->tag();
    }

    public function xref(): ?string
    {
        return $this->node()->xref();
    }

    /**
     * The view of an INDI or FAM record, null for other records. It is limited to the record:
     * what needs another one throws {@see \Korin\Gedcom\Exception\RequiresDocumentException},
     * pointers ({@see Individual::familyXrefs()}) do not.
     */
    public function view(): ?RecordView
    {
        return RecordView::of($this->node(), null);
    }

    /** 1-based line of the record in the file. */
    public function line(): int
    {
        return $this->document->lineBase() + 1;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Exception\RequiresDocumentException;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Version;

/**
 * A live view of a record: it reads from and writes to the tree, so nothing the view does not
 * know about is lost. Records it points to are looked up in the file it belongs to; a view of a
 * streamed record has none and throws {@see RequiresDocumentException} when asked for them.
 */
abstract class RecordView
{
    /**
     * @internal use {@see Document::individual()} and the like
     *
     * @param Document|IndexedDocument|null $file where the records it points to are; null for a streamed record
     */
    public function __construct(
        protected readonly Node $node,
        protected readonly Document|IndexedDocument|null $file,
    ) {}

    /** @var array<string, true> events of the record type, by tag */
    public const EVENTS = [];

    /** @internal the view of an INDI record; null for no record, another type or an unparsable line */
    public static function individual(?Node $record, Document|IndexedDocument|null $file): ?Individual
    {
        return $record !== null && $record->tag() === 'INDI' && !$record->document()->isInvalid($record->id()) ? new Individual($record, $file) : null;
    }

    /** @internal the view of a FAM record; null for no record, another type or an unparsable line */
    public static function family(?Node $record, Document|IndexedDocument|null $file): ?Family
    {
        return $record !== null && $record->tag() === 'FAM' && !$record->document()->isInvalid($record->id()) ? new Family($record, $file) : null;
    }

    /** @internal the view of a record by its tag, null for records without one */
    public static function of(Node $node, Document|IndexedDocument|null $file): ?self
    {
        return match ($node->tag()) {
            'INDI' => new Individual($node, $file),
            'FAM' => new Family($node, $file),
            default => null,
        };
    }

    public function node(): Node
    {
        return $this->node;
    }

    public function xref(): ?string
    {
        return $this->node->xref();
    }

    protected function version(): Version
    {
        return $this->node->document()->version();
    }

    /** @return list<string> the pointers of these substructures, null pointers left out */
    protected function pointers(string $tag): array
    {
        $pointers = [];
        foreach ($this->node->all($tag) as $child) {
            $pointer = $child->pointer();
            if ($pointer !== null && $pointer !== '@VOID@') {
                $pointers[] = $pointer;
            }
        }

        return $pointers;
    }

    /** The record of a pointer, or null when there is none of that type. */
    protected function lookUp(string $xref, string $tag): ?Node
    {
        if ($this->file === null) {
            throw new RequiresDocumentException('A streamed record has no other records: read the file with read() or open()');
        }
        $record = $this->file->record($xref);

        return $record !== null && $record->tag() === $tag ? $record : null;
    }

    /**
     * @param array<string, true> $tags
     *
     * @return list<Event> the events with these tags, in order
     */
    protected function eventsOf(array $tags): array
    {
        $events = [];
        foreach ($this->node->children() as $child) {
            if (isset($tags[$child->tag()])) {
                $events[] = new Event($child);
            }
        }

        return $events;
    }

    /**
     * Adds an event of the record type; one without a date or place is "Y", it happened. The
     * tree is not changed when the date cannot be written in the version.
     *
     * @throws InvalidValueException for a tag that is not an event of the record type, or a date the version cannot write
     */
    public function addEvent(string $tag, ?GedcomDate $date = null, ?Place $place = null): Event
    {
        if (!isset(static::EVENTS[$tag])) {
            throw new InvalidValueException("{$tag} is not an event of {$this->node->tag()}");
        }
        $this->node->document()->assertWritable();
        $date?->toGedcom($this->version());
        $event = new Event($this->node->addChild($tag, $date === null && $place === null && $tag !== 'EVEN' ? 'Y' : null));
        $event->setDate($date)->setPlace($place);

        return $event;
    }
}

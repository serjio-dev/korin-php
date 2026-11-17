<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Syntax\Node;

/** A live view of a FILE of a media object: path or URL, format and title. */
final readonly class MediaFile
{
    /** @internal use {@see MediaObject::files()} */
    public function __construct(private Node $node, private Node $record) {}

    public function node(): Node
    {
        return $this->node;
    }

    /** The reference: a relative path, a URL; in a GEDZIP, a path inside the archive. */
    public function path(): string
    {
        return (string) $this->node->value();
    }

    /** FORM: a media type in 7.0 ("image/jpeg"), a format in 5.x ("jpg"); the 5.5 FORM of the record. */
    public function format(): ?string
    {
        return $this->node->first('FORM')?->value() ?? $this->record->first('FORM')?->value();
    }

    public function title(): ?string
    {
        return $this->node->first('TITL')?->value();
    }
}

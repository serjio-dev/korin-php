<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

/** A live view of an OBJE record: its files and title. */
final class MediaObject extends RecordView
{
    public const TAG = 'OBJE';

    /** @return list<MediaFile> FILE, in order */
    public function files(): array
    {
        return array_map(fn(\Korin\Gedcom\Syntax\Node $file): MediaFile => new MediaFile($file, $this->node), $this->node->all('FILE'));
    }

    /** The title of the first file, or of the record (5.5). */
    public function title(): ?string
    {
        return ($this->files()[0] ?? null)?->title() ?? $this->text('TITL');
    }

    /** Adds a FILE with its FORM: a media type in 7.0 ("image/jpeg"), a format in 5.x ("jpg"). */
    public function addFile(string $path, string $format, ?string $title = null): MediaFile
    {
        $file = $this->node->addChild('FILE', $path);
        $file->addChild('FORM', $format);
        if ($title !== null) {
            $file->addChild('TITL', $title);
        }

        return new MediaFile($file, $this->node);
    }
}

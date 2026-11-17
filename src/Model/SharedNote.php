<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Version;

/** A live view of a note record: SNOTE in 7.0, NOTE in 5.x; its text is the record's value. */
final class SharedNote extends RecordView
{
    public static function tagIn(Version $version): string
    {
        return $version === Version::V7_0 ? 'SNOTE' : 'NOTE';
    }

    /** The text, CONC/CONT joined. */
    public function content(): string
    {
        return (string) $this->node->value();
    }

    public function setContent(string $text): self
    {
        $this->node->setValue($text === '' ? null : $text);

        return $this;
    }

    /** MIME, a 7.0 media type of the text ("text/html"); null for plain text. */
    public function mediaType(): ?string
    {
        return $this->text('MIME');
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

/** A live view of a REPO record: an archive or library that holds sources. */
final class Repository extends RecordView
{
    public const TAG = 'REPO';

    public function name(): ?string
    {
        return $this->text('NAME');
    }

    public function setName(?string $name): self
    {
        return $this->setText('NAME', $name);
    }

    /** ADDR, the lines of the address joined with "\n". */
    public function address(): ?string
    {
        return $this->text('ADDR');
    }
}

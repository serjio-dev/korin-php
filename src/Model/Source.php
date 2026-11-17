<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

/** A live view of a SOUR record: title, author, publication, abbreviation, text and repositories. */
final class Source extends RecordView
{
    public const TAG = 'SOUR';

    public function title(): ?string
    {
        return $this->text('TITL');
    }

    public function setTitle(?string $title): self
    {
        return $this->setText('TITL', $title);
    }

    public function author(): ?string
    {
        return $this->text('AUTH');
    }

    public function setAuthor(?string $author): self
    {
        return $this->setText('AUTH', $author);
    }

    public function publication(): ?string
    {
        return $this->text('PUBL');
    }

    public function setPublication(?string $publication): self
    {
        return $this->setText('PUBL', $publication);
    }

    public function abbreviation(): ?string
    {
        return $this->text('ABBR');
    }

    /** TEXT: a transcription of the source. */
    public function content(): ?string
    {
        return $this->text('TEXT');
    }

    /** @return list<string> REPO pointers; no other record is read */
    public function repositoryXrefs(): array
    {
        return $this->pointers('REPO');
    }

    /** @return list<Repository> */
    public function repositories(): array
    {
        $repositories = [];
        foreach ($this->repositoryXrefs() as $xref) {
            $repository = RecordView::typed($this->lookUp($xref, Repository::TAG), $this->file, Repository::class);
            if ($repository !== null) {
                $repositories[] = $repository;
            }
        }

        return $repositories;
    }

    /** CALN under the REPO pointing to the repository: where the source is kept there. */
    public function callNumber(Repository $repository): ?string
    {
        foreach ($this->node->all('REPO') as $line) {
            if ($line->pointer() === $repository->xref()) {
                return $line->first('CALN')?->value();
            }
        }

        return null;
    }

    /** Adds a REPO pointer (with CALN), unless there is one to the repository. */
    public function addRepository(Repository $repository, ?string $callNumber = null): self
    {
        $xref = $repository->xref();
        if ($xref === null || in_array($xref, $this->repositoryXrefs(), true)) {
            return $this;
        }
        $line = $this->node->addChild('REPO', $xref);
        if ($callNumber !== null) {
            $line->addChild('CALN', $callNumber);
        }

        return $this;
    }
}

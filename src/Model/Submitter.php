<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

/** A live view of a SUBM record: who contributed the data. */
final class Submitter extends RecordView
{
    public const TAG = 'SUBM';

    public function name(): ?string
    {
        return $this->text('NAME');
    }

    public function setName(?string $name): self
    {
        return $this->setText('NAME', $name);
    }

    public function address(): ?string
    {
        return $this->text('ADDR');
    }

    /** @return list<string> EMAIL values */
    public function emails(): array
    {
        return array_values(array_filter(array_map(static fn(\Korin\Gedcom\Syntax\Node $node): ?string => $node->value(), $this->node->all('EMAIL')), static fn(?string $email): bool => $email !== null));
    }
}

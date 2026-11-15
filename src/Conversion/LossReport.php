<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Version;

/**
 * Everything a conversion could not carry over as it was, in the order it was found. Empty
 * when upgrading. The JSON form is that of the corpus loss reports (losses.<version>.json).
 *
 * @implements \IteratorAggregate<int, Loss>
 */
final readonly class LossReport implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @param list<Loss> $entries */
    public function __construct(
        public Version $from,
        public Version $to,
        private array $entries = [],
    ) {}

    /** @return list<Loss> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return \ArrayIterator<int, Loss> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }

    /** @return array{from: string, to: string, entries: list<Loss>} */
    public function jsonSerialize(): array
    {
        return ['from' => $this->from->value, 'to' => $this->to->value, 'entries' => $this->entries];
    }
}

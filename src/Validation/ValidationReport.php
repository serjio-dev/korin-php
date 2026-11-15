<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;

/**
 * Diagnostics of one validation, in document order.
 *
 * @implements \IteratorAggregate<int, Diagnostic>
 */
final readonly class ValidationReport implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(private array $diagnostics) {}

    /** True when there are no errors; warnings and infos do not make a document invalid. */
    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /** @return list<Diagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return list<Diagnostic> */
    public function errors(): array
    {
        return $this->withSeverity(Severity::Error);
    }

    /** @return list<Diagnostic> */
    public function warnings(): array
    {
        return $this->withSeverity(Severity::Warning);
    }

    public function count(): int
    {
        return count($this->diagnostics);
    }

    /** @return \ArrayIterator<int, Diagnostic> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->diagnostics);
    }

    /** @return list<Diagnostic> */
    public function jsonSerialize(): array
    {
        return $this->diagnostics;
    }

    /** @return list<Diagnostic> */
    private function withSeverity(Severity $severity): array
    {
        return array_values(array_filter($this->diagnostics, static fn(Diagnostic $d): bool => $d->severity === $severity));
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

/**
 * One structure type of a profile, identified by its URI: the same tag can mean different
 * structures in different places (INDI.NAME and REPO.NAME).
 */
final readonly class Structure
{
    /**
     * @param ?string $payload payload type URI, "@<record URI>@" for pointers, or alternatives such as
     *                         "Y|<NULL>" and, in 5.x, "@<record URI>@|<SUBMITTER_TEXT>"; null when there is no payload
     * @param array<string, Substructure> $substructures by tag
     */
    public function __construct(
        public string $uri,
        public string $tag,
        public ?string $payload,
        public ?string $enumerationSet,
        public array $substructures,
    ) {}

    public function substructure(string $tag): ?Substructure
    {
        return $this->substructures[$tag] ?? null;
    }

    /** URI of the record type a pointer payload must point to; null when the payload cannot be a pointer. */
    public function pointerTarget(): ?string
    {
        return $this->payload !== null && preg_match('/(?:^|\|)@<([^>]+)>@(?:$|\|)/', $this->payload, $m) === 1 ? $m[1] : null;
    }
}

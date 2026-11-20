<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

use Korin\Gedcom\Syntax\Escaping;

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
     * @param array<'pointer'|'inline', array<string, Substructure>> $forms 5.x: the substructures of the form with a
     *                                                                   pointer and of the inline one, where they differ (SOUR @S1@ has no TEXT)
     */
    public function __construct(
        public string $uri,
        public string $tag,
        public ?string $payload,
        public ?string $enumerationSet,
        public array $substructures,
        public array $forms = [],
    ) {}

    public function substructure(string $tag): ?Substructure
    {
        return $this->substructures[$tag] ?? null;
    }

    /**
     * The substructures allowed with a payload: in 5.x, those of the form with a pointer or of the
     * inline one, where they differ. A text where the inline form has none (OBJE text) is a wrong
     * value, not a form: then any substructure of either.
     *
     * @return array<string, Substructure>
     */
    public function allowed(?string $payload): array
    {
        if ($this->forms === []) {
            return $this->substructures;
        }
        if ($payload !== null && Escaping::isPointer($payload)) {
            return $this->forms['pointer'];
        }
        $texts = array_diff(explode('|', (string) $this->payload), ['<NULL>', "@<{$this->pointerTarget()}>@"]);

        return $payload === null || $payload === '' || $texts !== [] ? $this->forms['inline'] : $this->substructures;
    }

    /** URI of the record type a pointer payload must point to; null when the payload cannot be a pointer. */
    public function pointerTarget(): ?string
    {
        return $this->payload !== null && preg_match('/(?:^|\|)@<([^>]+)>@(?:$|\|)/', $this->payload, $m) === 1 ? $m[1] : null;
    }
}

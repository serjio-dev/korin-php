<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

use Korin\Gedcom\Syntax\Document;
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
    ) {
        $this->inlineText = $forms !== [] && array_diff(explode('|', (string) $payload), ['<NULL>', "@<{$this->pointerTarget()}>@"]) !== [];
    }

    /** Whether the inline form has a text value (a SOUR description; an OBJE link has none). */
    private bool $inlineText;

    public function substructure(string $tag): ?Substructure
    {
        return $this->substructures[$tag] ?? null;
    }

    /**
     * The substructures allowed with a payload: in 5.x, those of the form with a pointer or of the
     * inline one, where they differ (form()).
     *
     * @return array<string, Substructure>
     */
    public function allowed(?string $payload): array
    {
        return $this->substructuresOf($this->form($payload));
    }

    /**
     * 5.x: the form a payload gives a structure with forms, 'pointer' or 'inline'. A malformed pointer
     * (@S1) is the form with a pointer; a text where the inline form has none (OBJE text) is a wrong
     * value, not a form: null, as for a structure without forms.
     *
     * @return ?('pointer'|'inline')
     */
    public function form(?string $payload): ?string
    {
        if ($this->forms === []) {
            return null;
        }
        if ($payload !== null && (Escaping::isPointer($payload) || (str_starts_with($payload, '@') && !str_starts_with($payload, '@@') && !str_starts_with($payload, '@#')))) {
            return 'pointer';
        }

        return $payload === null || $payload === '' || $this->inlineText ? 'inline' : null;
    }

    /**
     * The form of a structure of a document: its payload is read only for a structure with forms.
     *
     * @return ?('pointer'|'inline')
     */
    public function formOf(Document $document, int $id): ?string
    {
        return $this->forms === [] ? null : $this->form($document->payloadOf($id));
    }

    /**
     * @param ?('pointer'|'inline') $form
     *
     * @return array<string, Substructure> the substructures of a form; null: of any form
     */
    public function substructuresOf(?string $form): array
    {
        return $form === null ? $this->substructures : $this->forms[$form];
    }

    /** URI of the record type a pointer payload must point to; null when the payload cannot be a pointer. */
    public function pointerTarget(): ?string
    {
        return $this->payload !== null && preg_match('/(?:^|\|)@<([^>]+)>@(?:$|\|)/', $this->payload, $m) === 1 ? $m[1] : null;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * AFN, RFN and RIN become EXID with the TYPE the 7.0 term files define for the conversion:
 *
 * - AFN x → EXID x, TYPE https://gedcom.io/terms/v7/AFN;
 * - RFN xyz:123abc → EXID xyz, TYPE https://gedcom.io/terms/v7/RFN#123abc (the term file's
 *   recommended conversion), RFN x without ":" → EXID x, TYPE https://gedcom.io/terms/v7/RFN;
 * - RIN x in a file whose HEAD.SOUR is XYZ → EXID x, TYPE https://gedcom.io/terms/v7/RIN#XYZ,
 *   without the fragment when there is no HEAD.SOUR.
 *
 * Fragments are percent-encoded. Substructures of extensions are left alone.
 *
 * @internal
 */
final class Identifiers implements NodeRule
{
    private const TYPES = [
        'AFN' => 'https://gedcom.io/terms/v7/AFN',
        'RFN' => 'https://gedcom.io/terms/v7/RFN',
        'RIN' => 'https://gedcom.io/terms/v7/RIN',
    ];

    /** HEAD.SOUR of the input, read once. */
    private ?string $system = null;

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return array_keys(self::TYPES);
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($parent === 0 || str_starts_with($document->tagOf($parent), '_')) {
            return true;
        }
        $tag = $document->tagOf($id);
        $node = $document->node($id);
        $value = (string) $node->value();
        $type = self::TYPES[$tag];
        if ($tag === 'RFN' && str_contains($value, ':')) {
            [$value, $fragment] = explode(':', $value, 2);
            $type .= '#' . rawurlencode($fragment);
            $node->setValue($value);
        } elseif ($tag === 'RIN') {
            $this->system ??= (string) $context->head()?->first('SOUR')?->value();
            $type .= $this->system === '' ? '' : '#' . rawurlencode($this->system);
        }
        $document->setTag($id, 'EXID');
        $document->createNode($id, null, 'TYPE', $type);

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * EXID goes back to the 5.5.1 tag its TYPE names, where the record has that tag in 5.5.1 and
 * converting up again gives the same EXID:
 *
 * - EXID x, TYPE https://gedcom.io/terms/v7/AFN → AFN x;
 * - EXID xyz, TYPE https://gedcom.io/terms/v7/RFN#123abc → RFN xyz:123abc, without a fragment
 *   RFN xyz (not for a value with ":", which would read as one with a fragment);
 * - EXID x, TYPE https://gedcom.io/terms/v7/RIN#<HEAD.SOUR> → RIN x (without a fragment when the
 *   file has no HEAD.SOUR): the identifier of the program that wrote the file.
 *
 * Any other EXID, or one on a record without that tag in 5.5.1, is kept as the extension
 * _EXID with its TYPE (KL2001): REFN, a number the user assigned, would read as another kind
 * of identifier, and would stay REFN when converted up again. Extensions are left alone.
 *
 * @internal
 */
final class Identifiers implements NodeRule
{
    private const AFN = 'https://gedcom.io/terms/v7/AFN';
    private const RFN = 'https://gedcom.io/terms/v7/RFN';
    private const RIN = 'https://gedcom.io/terms/v7/RIN';

    /** The RIN type of the input: by its HEAD.SOUR, read once. */
    private ?string $rin = null;

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['EXID'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $record = $document->parentOf($id);
        if ($record === 0 || $document->parentOf($record) !== 0 || $context->inExtension($record)) {
            return true;
        }
        $value = (string) $context->value($id);
        $type = null;
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'TYPE') {
                $type = $child;
                break;
            }
        }
        $uri = $type === null ? null : (string) $context->value($type);
        $this->rin ??= self::RIN . $this->fragment((string) $document->head()?->first('SOUR')?->value());
        [$tag, $converted] = match (true) {
            $uri === self::AFN => ['AFN', $value],
            $uri === self::RFN && !str_contains($value, ':') => ['RFN', $value],
            $uri !== null && str_starts_with($uri, self::RFN . '#') && !str_contains($value, ':') => $this->rfn($value, substr($uri, strlen(self::RFN) + 1)),
            $uri === $this->rin => ['RIN', $value],
            default => [null, null],
        };
        if ($tag === null || $converted === null || !$this->has($document->tagOf($record), $tag)) {
            return $context->unsupported($id, 'KL2001', 'EXID has no 5.5.1 identifier for its TYPE');
        }
        $document->setTag($id, $tag);
        if ($type !== null) {
            $document->removeNode($type);
        }
        if ($converted !== $value) {
            $context->setValue($id, $converted);
        }

        return true;
    }

    /** @return array{?string, ?string} RFN with the fragment; nothing when the fragment would not come back the same */
    private function rfn(string $value, string $fragment): array
    {
        $decoded = rawurldecode($fragment);

        return $this->fragment($decoded) === '#' . $fragment ? ['RFN', $value . ':' . $decoded] : [null, null];
    }

    private function fragment(string $text): string
    {
        return $text === '' ? '' : '#' . rawurlencode($text);
    }

    private function has(string $record, string $tag): bool
    {
        $profile = Profile::for(Version::V5_5_1);
        $uri = $profile->record($record)?->uri;

        return $uri !== null && $profile->structure($uri)?->substructure($tag) !== null;
    }
}

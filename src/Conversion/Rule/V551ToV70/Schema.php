<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Syntax\Document;

/**
 * HEAD.SCHMA documents the extension tags with a standard meaning that the conversion writes:
 * "_PHRASE https://gedcom.io/terms/v7/PHRASE" (a relocated PHRASE) when a LANG of the input
 * has no BCP 47 tag (Languages). Whether one does comes from the prepass, so that HEAD is
 * complete when it is converted, as convertFile() needs. A tag the input already has is left
 * out, as its meaning there is not known; an existing SCHMA gets the TAG added (unless it has
 * one for _PHRASE already), an input without HEAD a HEAD. Extensions of
 * the input and those without a standard URI (_SUBN, _TYPE) are not documented: Korin does not
 * guess or invent what they mean.
 *
 * @internal
 */
final class Schema implements Rule
{
    private const PHRASE = 'https://gedcom.io/terms/v7/PHRASE';

    private bool $first = true;

    public function needsPrepass(): bool
    {
        return true;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        // HEAD is the first record; an input without one gets it here rather than from the converter, after the records.
        [$first, $this->first] = [$this->first, false];
        if (!$first || !$this->writesPhrase($context) || $context->inputHasExtension('_PHRASE')) {
            return;
        }
        $head = $document->tagOf($record) === 'HEAD' ? $record : $document->createNode(0, null, 'HEAD', null, $record);
        $schema = $document->node($head)->first('SCHMA');
        foreach ($schema?->all('TAG') ?? [] as $tag) {
            if (str_starts_with((string) $tag->value(), '_PHRASE ')) {
                return;
            }
        }
        $context->addText($schema?->id() ?? $document->createNode($head, null, 'SCHMA', null), 'TAG', '_PHRASE ' . self::PHRASE);
    }

    private function writesPhrase(Context $context): bool
    {
        foreach ($context->prepass()->languages as $language) {
            if ($language !== '' && Languages::tag($language) === null) {
                return true;
            }
        }

        return false;
    }
}

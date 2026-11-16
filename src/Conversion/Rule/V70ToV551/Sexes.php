<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;

/**
 * SEX X ("does not fit the typical definition of only Male or only Female") has no 5.5.1
 * value: it becomes SEX U with the 7.0 value in _SEX X, or SEX U alone under
 * LossPolicy::DropWithReport (KL3002). Converting up gives SEX X back.
 *
 * @internal
 */
final class Sexes implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['SEX'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($document->payloadOf($id) !== 'X' || ($context->sourceStructure($id)->uri ?? null) !== 'https://gedcom.io/terms/v7/SEX') {
            return true;
        }
        $drop = $context->options->policy === LossPolicy::DropWithReport;
        $context->loss('KL3002', Severity::Warning, $id, $drop ? StoredAs::Dropped : StoredAs::Extension, 'SEX X has no 5.5.1 value: SEX U');
        $context->setValue($id, 'U');
        if (!$drop) {
            $context->addText($id, '_SEX', 'X');
        }

        return true;
    }
}

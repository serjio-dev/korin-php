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
 * SEX X ("does not fit the typical definition of only Male or only Female") and 5.5.5 SEX N
 * ("not recorded") have no 5.5.1 value: they become SEX U with the value in _SEX X, or SEX U
 * alone under LossPolicy::DropWithReport (KL3002). Converting up gives SEX X back.
 *
 * @internal
 */
final readonly class Sexes implements NodeRule
{
    /** @param list<string> $values the source's values 5.5.1 does not have */
    public function __construct(private array $values = ['X']) {}

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
        $value = $document->payloadOf($id);
        if (!in_array($value, $this->values, true) || $context->sourceStructure($id)?->tag !== 'SEX') {
            return true;
        }
        $drop = $context->options->policy === LossPolicy::DropWithReport;
        $context->loss('KL3002', Severity::Warning, $id, $drop ? StoredAs::Dropped : StoredAs::Extension, "SEX {$value} has no 5.5.1 value: SEX U");
        $context->setValue($id, 'U');
        if (!$drop) {
            $context->addText($id, '_SEX', $value);
        }

        return true;
    }
}

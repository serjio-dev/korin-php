<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\Rule;
use Korin\Gedcom\Conversion\StoredAs;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;

/**
 * The first rule of every conversion, for what the reader could not read cleanly:
 *
 * - lines that are not GEDCOM lines (KV1001) have no place in any version, whatever the loss
 *   policy: they are dropped and reported (KL1001); empty lines carry no data and are dropped
 *   silently;
 * - bytes invalid in the source encoding (KV1003) were decoded as U+FFFD, which the output
 *   keeps; the line is reported (KL1002).
 *
 * @internal
 */
final class SourceLines implements Rule
{
    /** @var ?array<int, true> source lines with bytes invalid in the encoding */
    private ?array $undecodable = null;

    /** Whether the reader found lines that are not GEDCOM lines; without them and KV1003 there is nothing to do. */
    private bool $invalid = false;

    public function needsPrepass(): bool
    {
        return false;
    }

    public function record(Document $document, int $record, Context $context): void
    {
        if ($this->undecodable === null) {
            $this->undecodable = [];
            foreach ($document->diagnostics() as $diagnostic) {
                if ($diagnostic->code === 'KV1003' && $diagnostic->line !== null) {
                    $this->undecodable[$diagnostic->line] = true;
                }
                $this->invalid = $this->invalid || $diagnostic->code === 'KV1001';
            }
        }
        if (!$this->invalid && $this->undecodable === []) {
            return;
        }
        // Document order, so that the entries are too.
        $stack = [$record];
        while ($stack !== []) {
            $id = array_pop($stack);
            if ($document->isInvalid($id)) {
                if (($document->originalText($id)[0] ?? '') !== '') {
                    $context->loss('KL1001', Severity::Warning, $id, StoredAs::Dropped, 'A line that is not a GEDCOM line is dropped');
                }
                $document->removeNode($id);
                continue;
            }
            if ($this->undecodable !== [] && isset($this->undecodable[$document->lineOf($id)])) {
                $context->loss('KL1002', Severity::Warning, $id, StoredAs::Dropped, 'Bytes invalid in the source encoding are kept as U+FFFD');
            }
            array_push($stack, ...array_reverse($document->childIds($id)));
        }
    }
}

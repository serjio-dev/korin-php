<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;

/**
 * Xrefs and pointers. 7.0 allows only uppercase letters, digits and "_" in an xref and
 * reserves @VOID@: a record with any other xref (@fam 1@, @note@, @VOID@) gets @X<n>@, the next
 * free n in document order, and every pointer to it follows. A pointer to a record that does
 * not exist (compared case-sensitively: @test@ is not @TEST@) becomes the null pointer @VOID@,
 * except under NOTE: there it is more likely text written without "@@" escaping (@Smith family@)
 * and stays text (NOTE @@Smith family@), so nothing is lost. Needs the prepass: a pointer may come before the record it points to.
 *
 * @internal
 */
final class Xrefs implements NodeRule
{
    private const VALID = '/^@[A-Z0-9_]+@$/';
    private const VOID = '@VOID@';

    /** @var ?array<string, true> */
    private ?array $xrefs = null;

    /** @var array<string, string> source xref => its 7.0 xref, only for those that change */
    private array $renamed = [];

    public function needsPrepass(): bool
    {
        return true;
    }

    public function tags(): ?array
    {
        return null;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if ($this->xrefs === null) {
            $this->xrefs = $context->prepass()->xrefs;
            foreach (array_keys($this->xrefs) as $xref) {
                if ($xref === self::VOID || preg_match(self::VALID, $xref) !== 1) {
                    $this->renamed[$xref] = $context->newXref();
                }
            }
        }
        if ($document->isInvalid($id)) {
            return true;
        }
        if ($this->renamed !== []) {
            $xref = $document->xrefOf($id);
            if ($xref !== null && isset($this->renamed[$xref])) {
                $document->setXref($id, $this->renamed[$xref]);
            }
        }
        if (!$document->mayContain($id, '@')) {
            return true;
        }
        $payload = $document->payloadOf($id);
        if ($payload === null || !Escaping::isPointer($payload)) {
            return true;
        }
        if (isset($this->renamed[$payload])) {
            $document->setPayload($id, $this->renamed[$payload]);
        } elseif (!isset($this->xrefs[$payload])) {
            if ($document->tagOf($id) === 'NOTE') {
                $context->setValue($id, $payload);
            } elseif ($payload !== self::VOID) {
                $document->setPayload($id, self::VOID);
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Syntax\Document;

/**
 * Facts about the whole input that rules need before they see the first record (SPEC section 7,
 * "Streaming conversion"): from the document here, from a lexer-only pass in convertFile().
 *
 * @internal
 */
final readonly class Prepass
{
    /** @param array<string, true> $xrefs xrefs of all records */
    public function __construct(public array $xrefs) {}

    public static function of(Document $document): self
    {
        $xrefs = [];
        foreach ($document->childIds(0) as $id) {
            $xref = $document->xrefOf($id);
            if ($xref !== null) {
                $xrefs[$xref] = true;
            }
        }

        return new self($xrefs);
    }
}

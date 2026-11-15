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
    /**
     * @param array<string, true> $xrefs       xrefs of all records, in document order
     * @param list<int>           $submissions the SUBN records, which 7.0 keeps in HEAD
     */
    public function __construct(public array $xrefs, public array $submissions = []) {}

    public static function of(Document $document): self
    {
        $xrefs = [];
        $submissions = [];
        foreach ($document->childIds(0) as $id) {
            $xref = $document->xrefOf($id);
            if ($xref !== null) {
                $xrefs[$xref] = true;
            }
            if ($document->tagOf($id) === 'SUBN') {
                $submissions[] = $id;
            }
        }

        return new self($xrefs, $submissions);
    }
}

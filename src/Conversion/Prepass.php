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
     * @param list<string>        $languages   values of HEAD.LANG and SUBM.LANG, the only LANG
     *                                         structures of 5.x: whether 7.0 needs _PHRASE in HEAD.SCHMA
     */
    public function __construct(public array $xrefs, public array $submissions = [], public array $languages = []) {}

    public static function of(Document $document): self
    {
        $xrefs = [];
        $submissions = [];
        $languages = [];
        foreach ($document->childIds(0) as $id) {
            $xref = $document->xrefOf($id);
            if ($xref !== null) {
                $xrefs[$xref] = true;
            }
            $tag = $document->tagOf($id);
            if ($tag === 'SUBN') {
                $submissions[] = $id;
            } elseif ($tag === 'HEAD' || $tag === 'SUBM') {
                foreach ($document->childIds($id) as $child) {
                    if ($document->tagOf($child) === 'LANG') {
                        $languages[] = (string) $document->node($child)->value();
                    }
                }
            }
        }

        return new self($xrefs, $submissions, $languages);
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Version;

/**
 * The 5.5.1 NOTE record is the 7.0 shared note SNOTE, and a NOTE that points to it (in 5.5.1 a
 * NOTE pointer always points to a NOTE record) is SNOTE too, where 7.0 has SNOTE: under a TRAN
 * (5.5.1 ROMN, FONE) it stays NOTE for the structures rule, which keeps it as _NOTE. NOTE with
 * text stays NOTE.
 *
 * @internal
 */
final class NoteRecords implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['NOTE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        if ($document->tagOf($id) !== 'NOTE' || ($parent !== 0 && !Escaping::isPointer((string) $document->payloadOf($id)))) {
            return true;
        }
        $path = ['SNOTE'];
        for ($above = $parent; $above !== 0; $above = $document->parentOf($above)) {
            array_unshift($path, $document->tagOf($above));
        }
        if ($parent === 0 || Profile::for(Version::V7_0)->resolve($path) !== null) {
            $document->setTag($id, 'SNOTE');
        }

        return true;
    }
}

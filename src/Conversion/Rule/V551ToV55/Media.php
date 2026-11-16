<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV55;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * Media in the 5.5 structure, where FORM belongs to the object:
 *
 * - a link (OBJE without a pointer): the FORM of its first FILE moves up, before the FILE;
 * - a record: 5.5 has no FILE in it (a record holds the object itself, BLOB). The FORM and TITL
 *   of the first FILE move up, before it, and the FILE is kept as _FILE (Structures, KL2001);
 *   converting up puts them back (V55ToV551\Media). The 5.5 record lacks its BLOB.
 *
 * @internal
 */
final class Media implements NodeRule
{
    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['OBJE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $record = $document->parentOf($id) === 0;
        if ((!$record && $document->payloadOf($id) !== null) || $context->inExtension($id)) {
            return true;
        }
        foreach ($document->childIds($id) as $file) {
            if ($document->tagOf($file) !== 'FILE') {
                continue;
            }
            foreach ($document->childIds($file) as $child) {
                $tag = $document->tagOf($child);
                if ($tag === 'FORM' || ($record && $tag === 'TITL')) {
                    $document->moveNode($child, $id, $file);
                }
            }
            break;
        }

        return true;
    }
}

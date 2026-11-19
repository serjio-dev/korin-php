<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V55ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Syntax\Document;

/**
 * Media in the 5.5.1 structure, where FORM belongs to a FILE:
 *
 * - a link (OBJE without a pointer): FORM moves into its FILE;
 * - a record: 5.5 has no FILE in it, only the object itself (BLOB). The conversion from 5.5.1
 *   writes the file as _FILE and its FORM and TITL in the record (V551ToV55\Media); a record
 *   with _FILE, or with a FILE as some programs write in 5.5, gets them back in a FILE. A
 *   record without either keeps them, as extensions (Structures).
 *
 * 5.5.1 → 7.0 runs it on links inside an extension made a structure again ($restored): 5.5 NOTE.SOUR,
 * 5.5.1 _SOUR, keeps the 5.5 link.
 *
 * @internal
 */
final readonly class Media implements NodeRule
{
    public function __construct(private bool $restored = false) {}

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
        if ((!$record && $document->payloadOf($id) !== null) || $context->inExtension($id) || ($this->restored && ($record || !$context->inRestored($id)))) {
            return true;
        }
        $file = null;
        $moved = [];
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($file === null && ($tag === 'FILE' || ($record && $tag === '_FILE'))) {
                $file = $child;
            } elseif ($tag === 'FORM' || ($record && $tag === 'TITL')) {
                $moved[] = $child;
            }
        }
        if ($file === null || $moved === []) {
            return true;
        }
        $document->setTag($file, 'FILE');
        $before = $document->childIds($file)[0] ?? null;
        foreach ($moved as $child) {
            $document->moveNode($child, $file, $before);
        }

        return true;
    }
}

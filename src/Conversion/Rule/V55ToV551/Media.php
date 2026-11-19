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
 * 5.5.1 → 7.0 runs it on links ($links): a 5.5 NOTE.SOUR, which 5.5.1 makes _SOUR and 7.0 SOUR again,
 * keeps the 5.5 link, as do 5.5.1 files of programs that write it so; one with several FILE, or with a FILE
 * that has its FORM, is a 5.5.1 link with a FORM out of place (an extension, Structures).
 *
 * @internal
 */
final readonly class Media implements NodeRule
{
    public function __construct(private bool $links = false) {}

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
        if ($this->links && $record) {
            return true;
        }
        if ((!$record && $document->payloadOf($id) !== null) || $context->inExtension($id)) {
            return true;
        }
        $file = null;
        $moved = [];
        foreach ($document->childIds($id) as $child) {
            $tag = $document->tagOf($child);
            if ($this->links && $tag === 'FILE' && ($file !== null || $document->node($child)->first('FORM') !== null)) {
                // A 5.5.1 link with several files, or a file with its FORM: the FORM beside is not that file's.
                return true;
            }
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

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * 5.5.5 rules for the file as a whole that its grammar does not express: the file starts with
 * a byte order mark (KV1104, a warning: the BOM carries no data), and the SUBM record directly
 * follows HEAD (KV2004).
 *
 * @internal
 */
final class FileRule implements Rule
{
    public function check(Document $document, Profile $profile): array
    {
        if ($document->version() !== Version::V5_5_5) {
            return [];
        }
        $out = [];
        if (!$document->hasBom()) {
            $out[] = new Diagnostic('KV1104', Severity::Warning, 'A GEDCOM 5.5.5 file must start with a byte order mark');
        }
        // Records the validator does not check (extensions, invalid lines) do not count; without
        // a HEAD the reader has already reported the file (KV1004).
        $previous = null;
        foreach ($document->childIds(0) as $id) {
            $tag = $document->tagOf($id);
            if ($document->isInvalid($id) || str_starts_with($tag, '_')) {
                continue;
            }
            if ($tag === 'SUBM') {
                if ($previous !== null && $previous !== 'HEAD') {
                    $line = $document->lineOf($id);
                    $out[] = new Diagnostic('KV2004', Severity::Error, 'The SUBM record must directly follow HEAD', $line > 0 ? $line : null, $document->node($id)->path(), $profile->record('SUBM')?->uri);
                }
                break;
            }
            if ($previous === null && $tag !== 'HEAD') {
                break;
            }
            $previous = $tag;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Diagnostic;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Records\Records;
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
    private bool $active = false;

    private ?string $previous = null;

    private bool $done = false;

    /** @var list<Diagnostic> */
    private array $out = [];

    private Profile $profile;

    public function begin(Records $records, Profile $profile): void
    {
        $this->profile = $profile;
        $this->active = $records->version() === Version::V5_5_5;
        $this->previous = null;
        $this->done = !$this->active;
        $this->out = [];
        if ($this->active && !$records->hasBom()) {
            $this->out[] = new Diagnostic('KV1104', Severity::Warning, 'A GEDCOM 5.5.5 file must start with a byte order mark');
        }
    }

    public function record(Document $document, int $id, int $key): void
    {
        // Records the validator does not check (extensions, invalid lines) do not count; without
        // a HEAD the reader has already reported the file (KV1004).
        $tag = $document->tagOf($id);
        if ($this->done || $document->isInvalid($id) || str_starts_with($tag, '_')) {
            return;
        }
        if ($tag === 'SUBM') {
            if ($this->previous !== null && $this->previous !== 'HEAD') {
                $line = $document->lineOf($id);
                $this->out[] = new Diagnostic('KV2004', Severity::Error, 'The SUBM record must directly follow HEAD', $line > 0 ? $line : null, $document->node($id)->path(), $this->profile->record('SUBM')?->uri);
            }
            $this->done = true;

            return;
        }
        if ($this->previous === null && $tag !== 'HEAD') {
            $this->done = true;

            return;
        }
        $this->previous = $tag;
    }

    public function end(): array
    {
        return $this->out;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Writer;

use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\WriteOptions;

final class Writer
{
    public function serialize(Document $document, WriteOptions $options = new WriteOptions()): string
    {
        $encoding = $document->encoding();
        $codec = Codecs::for($encoding);
        $eol = $encoding->ascii($options->lineEnding ?? $document->lineEnding());

        $out = $document->hasBom() ? $encoding->bom() : '';
        $pendingEol = null;
        $lastIsNew = false;
        foreach ($document->walk() as $id) {
            if ($pendingEol !== null) {
                // A line that had no terminator (the last one in the input) is no longer last.
                $out .= $pendingEol === '' ? $eol : $pendingEol;
            }
            $original = $document->originalBytes($id);
            if ($original !== null) {
                [$content, $pendingEol] = $original;
                $out .= $content;
                $lastIsNew = false;
                continue;
            }
            $xref = $document->xrefOf($id);
            $payload = $document->payloadOf($id);
            $line = $document->depthOf($id)
                . ($xref !== null ? ' ' . $xref : '')
                . ' ' . $document->tagOf($id)
                . ($payload !== null ? ' ' . $payload : '');
            $out .= $codec->encode($line);
            $pendingEol = $document->originalEol($id);
            $lastIsNew = $document->lineOf($id) === 0;
        }
        // Keep a missing final terminator only when the input had none.
        if ($pendingEol !== null) {
            $out .= $pendingEol === '' && $lastIsNew ? $eol : $pendingEol;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support;

use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Node;

/** Semantic fingerprint as defined by gedcom-corpus schema/fingerprint.schema.json. */
final class Fingerprint
{
    private const VOLATILE_HEAD = ['DATE', 'SOUR', 'CHAR', 'GEDC'];

    /** @return array<string, mixed> */
    public static function of(Document $document): array
    {
        $records = [];
        foreach ($document->records() as $record) {
            if ($record->tag() !== 'TRLR') {
                $records[] = self::node($record, $record->tag() === 'HEAD' ? self::VOLATILE_HEAD : []);
            }
        }

        return ['fingerprintVersion' => 1, 'gedcomVersion' => $document->version()->value, 'records' => $records];
    }

    /**
     * @param list<string> $skip child tags to omit
     *
     * @return array<string, mixed>
     */
    private static function node(Node $node, array $skip = []): array
    {
        $out = [];
        if ($node->xref() !== null) {
            $out['xref'] = $node->xref();
        }
        $out['tag'] = $node->tag();
        if ($node->pointer() !== null) {
            $out['pointer'] = $node->pointer();
        } elseif (($value = $node->value()) !== null && $value !== '') {
            $out['value'] = \Normalizer::normalize($value, \Normalizer::NFC);
        }
        $children = [];
        foreach ($node->children() as $child) {
            if (!in_array($child->tag(), $skip, true)) {
                $children[] = self::node($child);
            }
        }
        if ($children !== []) {
            $out['children'] = $children;
        }

        return $out;
    }
}

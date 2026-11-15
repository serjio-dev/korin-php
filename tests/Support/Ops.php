<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support;

use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Node;

/** Applies gedcom-corpus ops.json operations through the public editing API. */
final class Ops
{
    /**
     * @param list<array<string, mixed>> $ops
     */
    public static function apply(Document $document, array $ops): void
    {
        foreach ($ops as $op) {
            match ($op['op']) {
                'setValue' => self::node($document, $op)->setValue(self::optionalString($op, 'value')),
                'addChild' => self::node($document, $op)->addChild(self::string($op, 'tag'), self::optionalString($op, 'value'), isset($op['position']) && is_int($op['position']) ? $op['position'] : null),
                'remove' => self::node($document, $op)->remove(),
                'renameXref' => $document->renameXref(self::string($op, 'from'), self::string($op, 'to')),
                'addRecord' => $document->addRecord(self::string($op, 'tag'), self::string($op, 'xref'), self::optionalString($op, 'value')),
                'removeRecord' => $document->removeRecord(self::string($op, 'xref')),
                default => throw new \UnexpectedValueException('Unknown op ' . var_export($op['op'], true)),
            };
        }
    }

    /** @param array<string, mixed> $op */
    private static function node(Document $document, array $op): Node
    {
        $path = self::string($op, 'path');

        return $document->find($path) ?? throw new \UnexpectedValueException("No node at {$path}");
    }

    /** @param array<string, mixed> $op */
    private static function string(array $op, string $key): string
    {
        $value = $op[$key] ?? null;

        return is_string($value) ? $value : throw new \UnexpectedValueException("Op field {$key} must be a string");
    }

    /** @param array<string, mixed> $op */
    private static function optionalString(array $op, string $key): ?string
    {
        return isset($op[$key]) ? self::string($op, $key) : null;
    }
}

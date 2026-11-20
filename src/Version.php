<?php

declare(strict_types=1);

namespace Korin\Gedcom;

enum Version: string
{
    /** Read only (best effort): validated with the 5.5 profile, converted to 5.5 and on from there. */
    case V4_0 = '4.0';
    case V5_5 = '5.5';
    case V5_5_1 = '5.5.1';
    case V5_5_5 = '5.5.5';
    case V7_0 = '7.0';

    /**
     * Maps a HEAD.GEDC.VERS value to a version; 7.0.x patches map to 7.0, 5.0–5.4 drafts (and
     * "5.01", the 5.1 of some programs) to 5.5, whose profile reads them; 4.0 as PAF writes it
     * ("4.0.4.18") and as Roots and Ultimate Family Tree do ("4+") to 4.0.
     */
    public static function fromDeclared(string $declared): ?self
    {
        $declared = trim($declared);

        return match (true) {
            $declared === '5.5.1' => self::V5_5_1,
            $declared === '5.5.5' => self::V5_5_5,
            $declared === '5.5', (bool) preg_match('/^5\.(0?[0-4])(\.\d+)?$/', $declared) => self::V5_5,
            (bool) preg_match('/^7\.0(\.\d+)?$/', $declared) => self::V7_0,
            (bool) preg_match('/^4(\.0(\.\d+)*|\+)$/', $declared) => self::V4_0,
            default => null,
        };
    }

    /** The version whose profile, grammars and line rules read this one: 5.5 for 4.0. */
    public function readAs(): self
    {
        return $this === self::V4_0 ? self::V5_5 : $this;
    }

    /** Whether documents of this version can be written other than unchanged (4.0 cannot). */
    public function isWritable(): bool
    {
        return $this !== self::V4_0;
    }

    /** @internal whether "@" is escaped only at the start of a line value (7.0) rather than everywhere (5.x) */
    public function escapesOnlyLeadingAt(): bool
    {
        return $this === self::V7_0;
    }
}

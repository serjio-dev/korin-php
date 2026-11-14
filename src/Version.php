<?php

declare(strict_types=1);

namespace Korin\Gedcom;

enum Version: string
{
    case V5_5 = '5.5';
    case V5_5_1 = '5.5.1';
    case V5_5_5 = '5.5.5';
    case V7_0 = '7.0';

    /**
     * Maps a HEAD.GEDC.VERS value to a version; 7.0.x patches map to 7.0,
     * 5.0–5.4 drafts are read with the 5.5 profile.
     */
    public static function fromDeclared(string $declared): ?self
    {
        $declared = trim($declared);

        return match (true) {
            $declared === '5.5.1' => self::V5_5_1,
            $declared === '5.5.5' => self::V5_5_5,
            $declared === '5.5', (bool) preg_match('/^5\.[0-4](\.\d+)?$/', $declared) => self::V5_5,
            (bool) preg_match('/^7\.0(\.\d+)?$/', $declared) => self::V7_0,
            default => null,
        };
    }

    /** Whether "@" is escaped only at the start of a line value (7.0) rather than everywhere (5.x). */
    public function escapesOnlyLeadingAt(): bool
    {
        return $this === self::V7_0;
    }
}

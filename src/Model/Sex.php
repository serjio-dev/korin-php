<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Version;

/** INDI.SEX. */
enum Sex: string
{
    case Male = 'M';
    case Female = 'F';
    /** 7.0 only: "does not fit the typical definition of only Male or only Female". */
    case Intersex = 'X';
    case Unknown = 'U';

    /** The value of a version, case-insensitive in 5.x; null for any other. */
    public static function parse(string $value, Version $version = Version::V7_0): ?self
    {
        $sex = self::tryFrom($version === Version::V7_0 ? $value : strtoupper($value));

        return $sex === self::Intersex && $version !== Version::V7_0 ? null : $sex;
    }

    public function isIn(Version $version): bool
    {
        return $this !== self::Intersex || $version === Version::V7_0;
    }
}

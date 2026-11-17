<?php

declare(strict_types=1);

namespace Korin\Gedcom\Extension;

use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Version;

/**
 * Documented mistakes of programs, which a reader corrects to read their files as they are
 * (SPEC section 3; docs/dialects.md). Each is reported (KV1010) and none changes a line: it
 * only decides the encoding or version the file is read in. {@see \Korin\Gedcom\ReadOptions::withDialects()}
 * turns them off.
 *
 * @internal
 */
final class Dialects
{
    /** CHAR.VERS values that name MacRoman under CHAR ASCII (GEDitCOM; MacFamilyTree before 8.3.5). */
    private const MAC_ROMAN = ['MACOSROMAN', 'MACINTOSH', 'MACROMAN'];

    /**
     * The encoding of a file whose CHAR a program is known to get wrong, when the content is not
     * ASCII (or UTF-8, which wins anyway); null when no mistake applies.
     *
     * @return ?array{Encoding, string} the encoding and the message of KV1010
     */
    public static function encoding(?Encoding $declared, ?string $charVersion, ?Program $program, ?string $programVersion): ?array
    {
        $charVersion = $charVersion === null ? null : strtoupper((string) preg_replace('/[\s_-]+/', '', $charVersion));
        if ($declared === Encoding::Ascii && in_array($charVersion, self::MAC_ROMAN, true)) {
            return [Encoding::MacRoman, 'CHAR ASCII with VERS naming MacRoman, as GEDitCOM and MacFamilyTree write MacRoman; read as MacRoman'];
        }
        $before = $programVersion !== null && (new SourceSystem('', $programVersion))->isBefore('6.1.12');
        if ($declared === Encoding::Cp437 && $program === Program::BrothersKeeper && ($programVersion === null || $before)) {
            return [Encoding::Windows1252, "Brother's Keeper before 6.1.12 writes CHAR IBMPC for Windows-1252; read as Windows-1252"];
        }

        return null;
    }

    /**
     * The version of a file whose program declares another; null when no mistake applies.
     *
     * @return ?array{Version, string} the version and the message of KV1010
     */
    public static function version(Node $head, Version $declared): ?array
    {
        $source = SourceSystem::of($head);
        if ($declared === Version::V5_5 && $source?->program() === Program::PersonalAncestralFile && $source->version() !== null && !$source->isBefore('5')) {
            return [Version::V5_5_1, 'PAF 5 writes GEDCOM 5.5.1 and declares 5.5; read as 5.5.1'];
        }

        return null;
    }
}

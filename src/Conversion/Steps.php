<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

/**
 * The conversion steps of the library, one per direction between adjacent versions.
 *
 * @internal
 */
final class Steps
{
    /** @return list<Step> */
    public static function all(): array
    {
        return [new Step\V551ToV70(), new Step\V70ToV551(), new Step\V55ToV551(), new Step\V551ToV55()];
    }
}

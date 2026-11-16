<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Extensions;
use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\Structures;
use Korin\Gedcom\Conversion\Rule\V551ToV55 as Rules;
use Korin\Gedcom\Conversion\Rule\Values;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 5.5.1 → 5.5 (SPEC section 7). Nothing is dropped by default: what 5.5 has no structure for
 * (EMAIL, FAX, WWW, MAP, FONE, ROMN, the FILE of a media record) becomes an extension and a
 * loss report entry.
 *
 * @internal
 */
final class V551ToV55 implements Step
{
    public function from(): Version
    {
        return Version::V5_5_1;
    }

    public function to(): Version
    {
        return Version::V5_5;
    }

    public function rules(): array
    {
        return [
            new Nodes(
                new Rules\Events(),
                new Rules\Media(),
                new Rules\Associations(),
                new Values(Version::V5_5_1, Version::V5_5, ['<MULTIMEDIA_FORMAT>' => ['jpg' => 'jpeg', 'tif' => 'tiff']]),
                new Extensions(Version::V5_5_1, Version::V5_5),
                new Structures(Version::V5_5),
            ),
        ];
    }
}

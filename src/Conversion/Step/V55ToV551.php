<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Extensions;
use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\Structures;
use Korin\Gedcom\Conversion\Rule\V55ToV551 as Rules;
use Korin\Gedcom\Conversion\Rule\Values;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 5.5 → 5.5.1 (SPEC section 7). Upgrading keeps everything: what 5.5.1 has no structure for
 * (BLOB, the AGE of a family event, a NOTE of a media link) becomes an extension, reported.
 *
 * @internal
 */
final class V55ToV551 implements Step
{
    public function from(): Version
    {
        return Version::V5_5;
    }

    public function to(): Version
    {
        return Version::V5_5_1;
    }

    public function rules(): array
    {
        return [
            new Nodes(
                new Rules\Media(),
                new Rules\Associations(),
                new Values(Version::V5_5, Version::V5_5_1, ['<MULTIMEDIA_FORMAT>' => ['jpeg' => 'jpg', 'tiff' => 'tif']]),
                new Extensions(Version::V5_5, Version::V5_5_1),
                new Structures(Version::V5_5_1),
            ),
        ];
    }
}

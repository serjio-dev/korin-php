<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Extensions;
use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\Structures;
use Korin\Gedcom\Conversion\Rule\V551ToV555 as Rules;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Sexes;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Submissions;
use Korin\Gedcom\Conversion\Rule\Values;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 5.5.1 → 5.5.5 (SPEC section 7). 5.5.5 is 5.5.1 made strict: inline sources and media become
 * records, SUBN moves into HEAD._SUBN, values follow the stricter grammars; what 5.5.5 has no
 * structure for (LDS ordinances, RESN, ALIA, AFN, the value of ADDR) becomes an extension and a
 * loss report entry.
 *
 * @internal
 */
final class V551ToV555 implements Step
{
    public function from(): Version
    {
        return Version::V5_5_1;
    }

    public function to(): Version
    {
        return Version::V5_5_5;
    }

    /** New instances for every conversion: rules keep state between the records of one input. */
    public function rules(): array
    {
        return [
            new Rules\FileHeader(),
            new Submissions(),
            new Rules\Submitters(),
            new Nodes(
                new Rules\InlineRecords(),
                new Rules\Addresses(),
                new Sexes(['X', 'N']),
                new Values(Version::V5_5_1, Version::V5_5_5, grammars: true, respell: Rules\Spellings::respell(...), kept: ['<PLACE_NAME>']),
                new Extensions(Version::V5_5_1, Version::V5_5_5, true),
                new Structures(Version::V5_5_5),
            ),
        ];
    }
}

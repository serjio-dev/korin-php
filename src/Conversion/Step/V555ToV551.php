<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Extensions;
use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\Structures;
use Korin\Gedcom\Conversion\Rule\V555ToV551 as Rules;
use Korin\Gedcom\Conversion\Rule\V70ToV551\Sexes;
use Korin\Gedcom\Conversion\Rule\V70ToV551\Submissions;
use Korin\Gedcom\Conversion\Rule\Values;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 5.5.5 → 5.5.1 (SPEC section 7): ADDR gets its value back, HEAD._SUBN becomes a SUBN record,
 * SEX X and N become SEX U with _SEX, and values 5.5.1 spells otherwise are respelled; what
 * 5.5.1 has no value for (a date without a year) becomes an extension and a loss report entry.
 *
 * @internal
 */
final class V555ToV551 implements Step
{
    public function from(): Version
    {
        return Version::V5_5_5;
    }

    public function to(): Version
    {
        return Version::V5_5_1;
    }

    /** New instances for every conversion: rules keep state between the records of one input. */
    public function rules(): array
    {
        return [
            new Rules\FileHeader(),
            new Submissions(),
            new Nodes(
                new Rules\Addresses(),
                new Rules\Dates(),
                new Sexes(['X', 'N']),
                // 5.5.1 programs write FORM png and pdf, which 5.5.1 does not list.
                new Values(Version::V5_5_5, Version::V5_5_1, ['<MULTIMEDIA_FORMAT>' => ['jpeg' => 'jpg', 'tiff' => 'tif']], true, Rules\Spellings::respell(...), ['<MULTIMEDIA_FORMAT>']),
                new Extensions(Version::V5_5_5, Version::V5_5_1, true),
                new Structures(Version::V5_5_1),
            ),
        ];
    }
}

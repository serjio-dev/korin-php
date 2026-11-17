<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\Structures;
use Korin\Gedcom\Conversion\Rule\V40ToV55 as Rules;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 4.0 → 5.5 (SPEC section 7, best effort). 4.0 has a dictionary of tags but no grammar: values
 * in words take their 5.5 form (Spellings), and what 5.5 has no structure for (HEAL, a tag
 * where 5.5 does not have it) becomes an extension, reported (KL2001). There is no step back:
 * 4.0 is read only.
 *
 * @internal
 */
final class V40ToV55 implements Step
{
    public function from(): Version
    {
        return Version::V4_0;
    }

    public function to(): Version
    {
        return Version::V5_5;
    }

    public function rules(): array
    {
        return [
            new Nodes(
                new Rules\Spellings(),
                new Structures(Version::V5_5),
            ),
        ];
    }
}

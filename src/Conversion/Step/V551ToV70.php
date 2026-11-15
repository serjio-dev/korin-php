<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\V551ToV70 as Rules;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 5.5.1 → 7.0, following the FamilySearch migration guide (SPEC section 7). Upgrading is
 * lossless: what 7.0 has no structure for stays an extension.
 *
 * @internal
 */
final class V551ToV70 implements Step
{
    public function from(): Version
    {
        return Version::V5_5_1;
    }

    public function to(): Version
    {
        return Version::V7_0;
    }

    /** New instances for every conversion: rules keep state between the records of one input. */
    public function rules(): array
    {
        return [
            new Rules\FileHeader(),
            new Nodes(new Rules\Xrefs(), new Rules\NoteRecords(), new Rules\TextValues()),
        ];
    }
}

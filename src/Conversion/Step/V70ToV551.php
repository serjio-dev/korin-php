<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Step;

use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\Conversion\Rule\V70ToV551 as Rules;
use Korin\Gedcom\Conversion\Step;
use Korin\Gedcom\Version;

/**
 * 7.0 → 5.5.1, the reverse of the FamilySearch migration guide (SPEC section 7). Nothing is
 * dropped by default: what 5.5.1 has no structure for becomes an extension and a loss report
 * entry.
 *
 * @internal
 */
final class V70ToV551 implements Step
{
    public function from(): Version
    {
        return Version::V7_0;
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
            new Rules\Submissions(),
            new Nodes(
                new Rules\TextValues(),
                new Rules\Voids(),
                new Rules\NoteRecords(),
                new Rules\Uids(),
                new Rules\Identifiers(),
                new Rules\Dates(),
                new Rules\Ages(),
                new Rules\Languages(),
                new Rules\Media(),
                new Rules\Enumerations(),
                new Rules\Translations(),
                new Rules\Sexes(),
                new Rules\Structures(),
            ),
        ];
    }
}

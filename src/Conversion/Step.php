<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\Version;

/**
 * A conversion between two adjacent versions of the chain 5.5 ↔ 5.5.1 ↔ 7.0, with 5.5.5
 * attached to 5.5.1 (SPEC section 7): its rules, applied in order to each record.
 *
 * @internal
 */
interface Step
{
    public function from(): Version;

    public function to(): Version;

    /** @return list<Rule> */
    public function rules(): array;
}

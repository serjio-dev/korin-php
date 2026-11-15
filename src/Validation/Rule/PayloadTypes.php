<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Structure;

/**
 * The payload types of one GEDCOM version for ValueRule. A type is one alternative of a
 * structure's payload: a 7.0 type URI, a 5.x element such as "<SEX_VALUE>", or "Y".
 *
 * @internal
 */
interface PayloadTypes
{
    /** Whether a structure needs a value or a substructure (KV3007). */
    public function requiresContent(): bool;

    /** Whether the type allows an empty payload without saying so with "<NULL>". */
    public function isOptional(string $type): bool;

    /**
     * Why the value is not of the type: code, severity and a message that follows the tag.
     *
     * @return list<array{string, Severity, string}> empty when the value is of the type
     */
    public function problems(Structure $structure, string $type, string $value): array;
}

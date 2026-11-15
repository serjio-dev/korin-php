<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

/** A structure as it may appear under a particular superstructure (or at the top level). */
final readonly class Substructure
{
    public function __construct(
        public string $tag,
        public string $uri,
        public Cardinality $cardinality,
    ) {}
}

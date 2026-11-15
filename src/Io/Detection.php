<?php

declare(strict_types=1);

namespace Korin\Gedcom\Io;

use Korin\Gedcom\Diagnostic;

final readonly class Detection
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public Encoding $encoding,
        public bool $hasBom,
        public ?string $declaredChar,
        public array $diagnostics = [],
    ) {}
}

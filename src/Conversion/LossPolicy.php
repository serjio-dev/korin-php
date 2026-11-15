<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

/** What a conversion does with data the target version has no structure for. */
enum LossPolicy
{
    /**
     * Keep it as an extension (a "_" tag) and report it; the default: no data is dropped. Lines
     * that are not GEDCOM lines are not data and are dropped under every policy (KL1001).
     */
    case PreserveAsExtension;

    /** Drop it and report it. */
    case DropWithReport;

    /** Throw ConversionException at the first loss. */
    case Fail;
}

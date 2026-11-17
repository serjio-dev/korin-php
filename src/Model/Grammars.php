<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Validation\Rule\DateGrammar;
use Korin\Gedcom\Validation\Rule\DateGrammar5;
use Korin\Gedcom\Validation\Rule\Grammar5;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;
use Korin\Gedcom\Version;

/**
 * The validator's grammars for the values of the model: a value object accepts exactly what
 * the validator finds valid, without warnings.
 *
 * @internal
 */
final class Grammars
{
    /** @var array<string, DateGrammar|DateGrammar5> */
    private static array $dates = [];

    /** A DateValue (7.0) or DATE_VALUE (5.x); $period for DatePeriod / DATE_PERIOD. */
    public static function isDate(Version $version, string $value, bool $period = false): bool
    {
        if ($version === Version::V7_0) {
            $grammar = self::$dates[$version->value] ??= new DateGrammar(Profile::for($version)->calendars());

            return $grammar instanceof DateGrammar && $grammar->check($period ? PayloadGrammar::DATE_PERIOD : PayloadGrammar::DATE, $value) === DateGrammar::VALID;
        }
        $grammar = self::$dates[$version->value] ??= new DateGrammar5(Profile::for($version)->enumeration(...), $version === Version::V5_5_5);

        return $grammar instanceof DateGrammar5 && $grammar->check($period ? 'DATE_PERIOD' : 'DATE_VALUE', $value) === DateGrammar5::VALID;
    }

    public static function isAge(Version $version, string $value): bool
    {
        return $version === Version::V7_0
            ? $value === '' || PayloadGrammar::matches(PayloadGrammar::AGE, $value)
            : Grammar5::matches('AGE_AT_EVENT', $value, $version === Version::V5_5_5);
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

/** What a {@see GedcomDate} says about its dates. */
enum DateKind: string
{
    /** No date: an empty value, or only a phrase ("(spring 1900)" in 5.x, a 7.0 PHRASE). */
    case None = '';
    /** One date: "6 DEC 1907"; with a phrase, the 5.x "INT 6 DEC 1907 (text)". */
    case Date = 'DATE';
    case About = 'ABT';
    case Calculated = 'CAL';
    case Estimated = 'EST';
    case Before = 'BEF';
    case After = 'AFT';
    /** BET date AND date. */
    case Between = 'BET';
    /** FROM date, a period without an end. */
    case From = 'FROM';
    /** TO date, a period without a start. */
    case To = 'TO';
    /** FROM date TO date. */
    case Period = 'FROM TO';

    /** How many dates the kind has: 0, 1 or 2. */
    public function dates(): int
    {
        return match ($this) {
            self::None => 0,
            self::Between, self::Period => 2,
            default => 1,
        };
    }

    /** FROM, TO or FROM … TO: a 7.0 DatePeriod, a 5.x DATE_PERIOD. */
    public function isPeriod(): bool
    {
        return in_array($this, [self::From, self::To, self::Period], true);
    }
}

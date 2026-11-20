<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV555;

/**
 * The 5.5.5 spelling of 5.5.1 values its stricter grammars reject (for Rule\Values): ages with
 * a space after < and > and without leading zeroes or zero parts ("<01y 0m" → "< 1y"), months
 * past 11 in years ("42m" → "3y 6m"; days stay, a month has no fixed length), dates
 * with days without a leading zero and years of at least 3 digits ("05 JAN 50" → "5 JAN 050"),
 * times with hours without one and two-digit fractions ("09:05:00.5" → "9:05:00.50"), place
 * names with ", " between parts. Null when there is none: an age of 0y, a longer fraction.
 *
 * @internal
 */
final class Spellings
{
    private const KEYWORDS = ['FROM' => true, 'TO' => true, 'BEF' => true, 'AFT' => true, 'BET' => true, 'AND' => true,
        'ABT' => true, 'CAL' => true, 'EST' => true, 'INT' => true, 'BCE' => true, 'BC' => true, 'B.C.' => true];

    public static function respell(string $grammar, string $value): ?string
    {
        return match ($grammar) {
            'AGE_AT_EVENT' => self::age($value),
            'DATE_VALUE', 'DATE_PERIOD', 'DATE_EXACT' => self::date($value),
            'TIME_VALUE' => preg_match('/^0?([0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)(?:\.([0-9]{1,2}))?$/', $value, $m) === 1
                ? $m[1] . (isset($m[2]) ? '.' . str_pad($m[2], 2, '0') : '') : null,
            'PLACE_NAME' => (string) preg_replace('/ *, */', ', ', trim($value)),
            default => null,
        };
    }

    private static function age(string $value): ?string
    {
        if (preg_match('/^([<>]?) *((?:[0-9]+[ymd] *)+)$/i', trim($value), $m) !== 1) {
            return null;
        }
        $parts = [];
        preg_match_all('/([0-9]+)([ymd])/i', $m[2], $all, PREG_SET_ORDER);
        $units = array_map(static fn(array $part): string => strtolower($part[2]), $all);
        if (in_array(implode('', $units), ['ym', 'm', 'ymd', 'md'], true)) {
            // One of each, in order: months past 11 go into the years.
            $numbers = array_combine($units, array_map(static fn(array $part): int => (int) $part[1], $all));
            $years = ($numbers['y'] ?? 0) + intdiv($numbers['m'], 12);
            $all = array_filter([[null, (string) $years, 'y'], [null, (string) ($numbers['m'] % 12), 'm'], [null, (string) ($numbers['d'] ?? 0), 'd']], static fn(array $part): bool => $part[1] !== '0');
        }
        foreach ($all as [, $number, $unit]) {
            $number = ltrim($number, '0');
            if ($number !== '') {
                $parts[] = $number . strtolower($unit);
            }
        }

        return $parts === [] ? null : ($m[1] === '' ? '' : $m[1] . ' ') . implode(' ', $parts);
    }

    private static function date(string $value): ?string
    {
        if (str_contains($value, '(')) {
            return null;
        }
        $tokens = explode(' ', trim($value));
        foreach ($tokens as $i => $token) {
            if (preg_match('/^[0-9]+$/', $token) !== 1) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $day = $next !== null && preg_match('/^[A-Z]{3,4}$/i', $next) === 1 && !isset(self::KEYWORDS[strtoupper($next)]);
            $tokens[$i] = $day ? (ltrim($token, '0') === '' ? $token : ltrim($token, '0')) : str_pad($token, 3, '0', STR_PAD_LEFT);
        }

        return implode(' ', $tokens);
    }
}

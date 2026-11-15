<?php

declare(strict_types=1);

namespace Korin\Gedcom\Writer;

/**
 * Splits an escaped 5.x line value into CONC parts. Splits fall only between grapheme clusters,
 * never inside "@@" or an "@#…@" escape, and never next to a space (5.5.1: "values that are
 * split for a CONC tag must always be split at a non-space"). Parts are as long as allowed.
 *
 * @internal
 */
final class ConcSplitter
{
    private const UNIT = '/@#[^@]*@|@@|\X/u';

    /**
     * @param int $first room for the value on the first line
     * @param int $rest room for the value on each CONC line
     * @param \Closure(string): int $measure length of a piece in the limit's units
     *
     * @return non-empty-list<string> the first line's value followed by the CONC values
     */
    public static function split(string $payload, int $first, int $rest, \Closure $measure): array
    {
        if ($payload === '' || $measure($payload) <= $first) {
            return [$payload];
        }
        preg_match_all(self::UNIT, $payload, $m);
        $units = $m[0];
        $sizes = array_map($measure, $units);

        $parts = [];
        $start = 0;
        $count = count($units);
        $room = $first;
        do {
            $end = $start;
            $used = 0;
            while ($end < $count && $used + $sizes[$end] <= $room) {
                $used += $sizes[$end];
                ++$end;
            }
            if ($end === $start) {
                ++$end; // a single unit longer than the room still goes on its own line
            }
            if ($end < $count) {
                $end = self::nonSpaceBoundary($units, $start, $end);
            }
            $parts[] = implode('', array_slice($units, $start, $end - $start));
            $start = $end;
            $room = $rest;
        } while ($start < $count);

        return $parts;
    }

    /**
     * Moves the split left until neither side of it is a space; keeps it if there is no such place.
     *
     * @param list<string> $units
     */
    private static function nonSpaceBoundary(array $units, int $start, int $end): int
    {
        for ($at = $end; $at > $start; --$at) {
            if ($units[$at - 1] !== ' ' && $units[$at] !== ' ') {
                return $at;
            }
        }

        return $end;
    }
}

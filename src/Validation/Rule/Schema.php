<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;

/**
 * The documented extension tags of a 7.0 HEAD.SCHMA that stand for a standard term: a record
 * type, a calendar or a month. A tag definition makes its tag an abbreviation for the URI
 * (specification chapter 1, "Extension tags"), so `_USER` defined as
 * https://gedcom.io/terms/v7/record-SUBM is a SUBM record for the pointers to it, and a date in
 * `_CAL`, defined as https://gedcom.io/terms/v7/cal-FRENCH_R, is a French Republican date. The
 * contents of such a record are an extension's and are not checked.
 *
 * @internal
 */
final readonly class Schema
{
    /**
     * @param array<string, string> $records extension tag => standard record tag
     * @param array<string, string> $calendars extension tag => standard calendar
     * @param array<string, string> $months extension tag => standard month
     */
    public function __construct(
        public array $records = [],
        public array $calendars = [],
        public array $months = [],
    ) {}

    /** The tag definitions of the HEAD record $head. */
    public static function of(Document $document, int $head, Profile $profile): self
    {
        $recordTags = [];
        foreach ($profile->records() as $tag => $record) {
            $recordTags[$record->uri] = $tag;
        }
        $calendarTags = array_flip(array_map(static fn(array $calendar): string => $calendar['uri'], $profile->calendars()));
        $monthTags = $profile->months();
        $records = $calendars = $months = [];
        foreach ($document->node($head)->first('SCHMA')?->all('TAG') ?? [] as $definition) {
            if (preg_match('/^(_[A-Z0-9_]+) +(\S+)$/', (string) $definition->value(), $m) !== 1) {
                continue;
            }
            [, $tag, $uri] = $m;
            if (isset($recordTags[$uri])) {
                $records[$tag] = $recordTags[$uri];
            } elseif (isset($calendarTags[$uri])) {
                $calendars[$tag] = $calendarTags[$uri];
            } elseif (isset($monthTags[$uri])) {
                $months[$tag] = $monthTags[$uri];
            }
        }

        return new self($records, $calendars, $months);
    }
}

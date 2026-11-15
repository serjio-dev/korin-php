<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;

/**
 * GEDCOM 7.0 payload types: PayloadGrammar and DateGrammar, enumerations from the profile.
 *
 * @internal
 */
final readonly class Payloads7 implements PayloadTypes
{
    private DateGrammar $dates;

    public function __construct(private Profile $profile)
    {
        $this->dates = new DateGrammar($profile->calendars());
    }

    public function requiresContent(): bool
    {
        return true;
    }

    public function isOptional(string $type): bool
    {
        return isset(PayloadGrammar::OPTIONAL[$type]);
    }

    public function problems(Structure $structure, string $type, string $value): array
    {
        if ($type === 'Y') {
            return $value === 'Y' ? [] : [['KV3005', Severity::Error, 'must be "Y" or empty']];
        }
        if ($type === PayloadGrammar::ENUM || $type === PayloadGrammar::LIST_ENUM) {
            $permitted = $this->profile->enumeration((string) $structure->enumerationSet);
            $problems = [];
            foreach ($type === PayloadGrammar::ENUM ? [$value] : PayloadGrammar::listItems($value) as $item) {
                if (!PayloadGrammar::isEnumValue($item, $permitted)) {
                    $problems[] = ['KV3006', Severity::Error, 'does not permit "' . PayloadGrammar::fragment($item) . '"; expected one of ' . implode(', ', $permitted) . ' or an extension value'];
                }
            }

            return $problems;
        }
        if (isset(PayloadGrammar::DATES[$type])) {
            return match ($this->dates->check($type, $value)) {
                DateGrammar::VALID => [],
                DateGrammar::DAY_OUT_OF_RANGE => [['KV3008', Severity::Warning, 'has a day beyond the length of its month']],
                DateGrammar::INVALID => [['KV3005', Severity::Error, 'must be ' . PayloadGrammar::NAMES[$type]]],
            };
        }

        return PayloadGrammar::matches($type, $value) ? [] : [['KV3005', Severity::Error, 'must be ' . (PayloadGrammar::NAMES[$type] ?? $type)]];
    }
}

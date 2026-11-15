<?php

declare(strict_types=1);

namespace Korin\Gedcom\Validation\Rule;

use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;

/**
 * GEDCOM 5.x payload types: the primitive elements of the profile ("<SEX_VALUE>") as
 * enumerations or grammars (Grammar5, DateGrammar5); other elements are text. Enumerations
 * are case-insensitive. An empty payload needs "<NULL>" among the alternatives.
 *
 * @internal
 */
final readonly class Payloads5 implements PayloadTypes
{
    /** Longer enumerations are not listed in messages. */
    private const LISTED = 15;

    private DateGrammar5 $dates;

    public function __construct(private Profile $profile)
    {
        $this->dates = new DateGrammar5($profile->enumeration(...));
    }

    public function requiresContent(): bool
    {
        return false;
    }

    public function isOptional(string $type): bool
    {
        return false;
    }

    public function problems(Structure $structure, string $type, string $value): array
    {
        if ($type === 'Y') {
            return $value === 'Y' ? [] : [['KV3005', Severity::Error, 'must be "Y" or empty']];
        }
        $grammar = $this->profile->type($type);
        if ($grammar === null) {
            return [];
        }
        if ($grammar === 'ENUM') {
            return $this->enumeration($type, $value);
        }
        if (isset(Grammar5::DATES[$grammar])) {
            return match ($this->dates->check($grammar, $value)) {
                DateGrammar5::VALID => [],
                DateGrammar5::DAY_OUT_OF_RANGE => [['KV3008', Severity::Warning, 'has a day beyond the length of its month']],
                DateGrammar5::BAD_DUAL_YEAR => [['KV3009', Severity::Warning, 'has a dual year whose second year does not follow the first']],
                DateGrammar5::INVALID => [['KV3005', Severity::Error, 'must be ' . Grammar5::NAMES[$grammar]]],
            };
        }

        return Grammar5::matches($grammar, $value) ? [] : [['KV3005', Severity::Error, 'must be ' . (Grammar5::NAMES[$grammar] ?? $grammar)]];
    }

    /** @return list<array{string, Severity, string}> */
    private function enumeration(string $element, string $value): array
    {
        $permitted = $this->profile->enumeration($element);
        foreach ($permitted as $option) {
            // "(<ROLE_DESCRIPTOR>)": any text in parentheses.
            if (strcasecmp($option, $value) === 0 || (preg_match('/^\(<[A-Z_]+>\)$/', $option) === 1 && preg_match('/^\(.+\)$/s', $value) === 1)) {
                return [];
            }
        }
        $expected = count($permitted) <= self::LISTED ? 'one of ' . implode(', ', $permitted) : 'one of the ' . count($permitted) . " values of {$element}";

        return [['KV3006', Severity::Error, 'does not permit "' . PayloadGrammar::fragment($value) . "\"; expected {$expected}"]];
    }
}

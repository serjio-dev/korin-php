<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V70ToV551;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\V551ToV70\Enumerations as Upgrade;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

/**
 * Enumeration values in 5.5.1 case and spelling (BIRTH → birth, PRE_1970 → PRE-1970, DNS_CAN →
 * DNS/CAN); OTHER with a PHRASE → the PHRASE (TYPE OTHER + PHRASE Screen → Screen), and a role
 * description in parentheses where 5.5.1 has one (ROLE OTHER + PHRASE midwife → (midwife)).
 * ASSO.ROLE becomes RELA (FRIEND → Friend, GODP + PHRASE godmother → godmother), FORM.MEDI
 * becomes FORM.TYPE.
 *
 * Every value is one that converts up to the same value and PHRASE again. A PHRASE that cannot
 * join the value stays for the structures rule; a value 5.5.1 does not list (PEDI OTHER) is
 * written in 5.5.1 case all the same. SEX X is left to the structures rule.
 *
 * @internal
 */
final readonly class Enumerations implements NodeRule
{
    /** 7.0 structure => 5.5.1 element */
    private const ELEMENTS = [
        'https://gedcom.io/terms/v7/FAMC-ADOP' => '<ADOPTED_BY_WHICH_PARENT>',
        'https://gedcom.io/terms/v7/FAMC-STAT' => '<CHILD_LINKAGE_STATUS>',
        'https://gedcom.io/terms/v7/MEDI' => '<SOURCE_MEDIA_TYPE>',
        'https://gedcom.io/terms/v7/NAME-TYPE' => '<NAME_TYPE>',
        'https://gedcom.io/terms/v7/PEDI' => '<PEDIGREE_LINKAGE_TYPE>',
        'https://gedcom.io/terms/v7/QUAY' => '<CERTAINTY_ASSESSMENT>',
        'https://gedcom.io/terms/v7/RESN' => '<RESTRICTION_NOTICE>',
        'https://gedcom.io/terms/v7/ROLE' => '<ROLE_IN_EVENT>',
        'https://gedcom.io/terms/v7/SEX' => '<SEX_VALUE>',
        'https://gedcom.io/terms/v7/SOUR-EVEN' => '<EVENT_TYPE_CITED_FROM>',
        'https://gedcom.io/terms/v7/DATA-EVEN' => '<EVENTS_RECORDED>',
        'https://gedcom.io/terms/v7/ord-STAT' => '<LDS_BAPTISM_DATE_STATUS>',
    ];

    /** The ordinance a 7.0 ord-STAT is in => its 5.5.1 element */
    private const STATUSES = [
        'ENDL' => '<LDS_ENDOWMENT_DATE_STATUS>',
        'SLGC' => '<LDS_CHILD_SEALING_DATE_STATUS>',
        'SLGS' => '<LDS_SPOUSE_SEALING_DATE_STATUS>',
    ];

    /** Elements written in lowercase that 5.5.1 does not enumerate. */
    private const LOWERCASE = ['<NAME_TYPE>' => true];

    private const LISTS = ['<EVENTS_RECORDED>' => true, '<RESTRICTION_NOTICE>' => true];

    private const RENAMED = ['PRE_1970' => 'PRE-1970', 'DNS_CAN' => 'DNS/CAN'];

    /** Values written the same in 5.5.1. */
    private const SAME = [
        'SEX' => ['M' => true, 'F' => true, 'U' => true, 'X' => true],
        'QUAY' => ['0' => true, '1' => true, '2' => true, '3' => true],
    ];

    private Upgrade $upgrade;

    public function __construct()
    {
        $this->upgrade = new Upgrade();
    }

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['ADOP', 'EVEN', 'MEDI', 'PEDI', 'QUAY', 'RESN', 'ROLE', 'SEX', 'STAT', 'TYPE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (isset(self::SAME[$document->tagOf($id)][(string) $document->payloadOf($id)]) && !$document->hasChildren($id)) {
            return true;
        }
        $element = self::ELEMENTS[$context->sourceStructure($id)->uri ?? ''] ?? null;
        if ($element === null) {
            return true;
        }
        $parent = $document->parentOf($id);
        $above = $document->tagOf($parent);
        if ($element === '<ROLE_IN_EVENT>' && $above === 'ASSO') {
            $element = '<RELATION_IS_DESCRIPTOR>';
            $document->setTag($id, 'RELA');
        } elseif ($element === '<SOURCE_MEDIA_TYPE>' && $above === 'FORM') {
            $document->setTag($id, 'TYPE');
        } elseif ($element === '<LDS_BAPTISM_DATE_STATUS>') {
            $element = self::STATUSES[$above] ?? $element;
        }
        $value = (string) $context->value($id);
        $phrase = null;
        foreach ($document->childIds($id) as $child) {
            if ($document->tagOf($child) === 'PHRASE') {
                $phrase = $child;
                break;
            }
        }
        $text = $phrase === null ? null : (string) $context->value($phrase);
        $spelled = $this->spell($element, $value);
        $converted = $text === null ? null : $this->first($element, ($element === '<ROLE_IN_EVENT>' ? ["({$text})", $text] : [$text]), $value, $text);
        if ($converted !== null) {
            $document->removeNode($phrase);
        } else {
            $converted = $this->first($element, [$spelled, $value], $value, null) ?? $value;
        }
        if ($converted !== $value) {
            $context->setValue($id, $converted);
        }

        return true;
    }

    /** The value in 5.5.1 spelling: as 5.5.1 lists it, otherwise in the case of its list. */
    private function spell(string $element, string $value): string
    {
        $listed = Profile::for(Version::V5_5_1)->enumeration($element);
        $lowercase = isset(self::LOWERCASE[$element]) || ($listed !== [] && strtolower(implode('', $listed)) === implode('', $listed));
        $items = isset(self::LISTS[$element]) ? array_map(trim(...), explode(',', $value)) : [$value];
        foreach ($items as &$item) {
            $item = self::RENAMED[$item] ?? $item;
            foreach ($listed as $entry) {
                if (strcasecmp($entry, $item) === 0) {
                    $item = $entry;
                    continue 2;
                }
            }
            $item = match (true) {
                $element === '<RELATION_IS_DESCRIPTOR>' => ucfirst(strtolower($item)),
                $lowercase => strtolower($item),
                default => $item,
            };
        }

        return implode(', ', $items);
    }

    /**
     * The first value that converts up to the value and PHRASE.
     *
     * @param list<string> $candidates
     */
    private function first(string $element, array $candidates, string $value, ?string $phrase): ?string
    {
        foreach ($candidates as $candidate) {
            if (($this->upgrade->convert($element, $candidate) ?? [$candidate, null]) === [$value, $phrase]) {
                return $candidate;
            }
        }

        return null;
    }
}

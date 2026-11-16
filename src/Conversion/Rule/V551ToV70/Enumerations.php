<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule\V551ToV70;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Validation\Rule\PayloadGrammar;
use Korin\Gedcom\Version;

/**
 * Enumeration values in 7.0 case and spelling (birth → BIRTH, PRE-1970 → PRE_1970, DNS/CAN →
 * DNS_CAN). A value the 7.0 set does not have becomes OTHER with the value as PHRASE where 7.0
 * has both ("1 NAME … 2 TYPE Screen" → TYPE OTHER, PHRASE Screen), and stays as it is
 * elsewhere. A 5.5.1 role description "(text)" is OTHER with PHRASE text.
 *
 * ASSO.RELA, free text in 5.5.1, becomes ROLE: the value when it names a 7.0 role (friend →
 * FRIEND), the role for a common word with the word as PHRASE (neighbor → NGHBR, PHRASE
 * neighbor; "(godmother)" → GODP, PHRASE godmother), otherwise OTHER with PHRASE, and OTHER
 * when it is empty. FORM.TYPE, the medium of a 5.5.1 media file,
 * becomes FORM.MEDI.
 *
 * @internal
 */
final class Enumerations implements NodeRule
{
    private const SET = 'https://gedcom.io/terms/v7/enumset-';

    /** 5.5.1 element => 7.0 enumeration set and whether 7.0 has PHRASE there */
    private const ELEMENTS = [
        '<ADOPTED_BY_WHICH_PARENT>' => ['ADOP', true],
        '<CERTAINTY_ASSESSMENT>' => ['QUAY', false],
        '<CHILD_LINKAGE_STATUS>' => ['FAMC-STAT', true],
        '<EVENT_TYPE_CITED_FROM>' => ['EVENATTR', true],
        '<EVENTS_RECORDED>' => ['EVENATTR', false],
        '<LDS_BAPTISM_DATE_STATUS>' => ['ord-STAT', false],
        '<LDS_CHILD_SEALING_DATE_STATUS>' => ['ord-STAT', false],
        '<LDS_ENDOWMENT_DATE_STATUS>' => ['ord-STAT', false],
        '<LDS_SPOUSE_SEALING_DATE_STATUS>' => ['ord-STAT', false],
        '<NAME_TYPE>' => ['NAME-TYPE', true],
        '<PEDIGREE_LINKAGE_TYPE>' => ['PEDI', true],
        '<RELATION_IS_DESCRIPTOR>' => ['ROLE', true],
        '<RESTRICTION_NOTICE>' => ['RESN', false],
        '<ROLE_IN_EVENT>' => ['ROLE', true],
        '<SEX_VALUE>' => ['SEX', false],
        '<SOURCE_MEDIA_TYPE>' => ['MEDI', true],
    ];

    /**
     * Tags whose usual values are the same in 7.0 wherever 5.5.1 has the tag: values that need
     * no lookup of the structure.
     */
    private const SAME = [
        'SEX' => ['M' => true, 'F' => true, 'U' => true],
        'QUAY' => ['0' => true, '1' => true, '2' => true, '3' => true],
        'ROLE' => ['CHIL' => true, 'HUSB' => true, 'WIFE' => true, 'MOTH' => true, 'FATH' => true, 'SPOU' => true],
    ];

    /** Enumerations whose payload is a list in 7.0: "BIRT, DEAT". */
    private const LISTS = ['<EVENTS_RECORDED>' => true, '<RESTRICTION_NOTICE>' => true];

    /** A 5.5.1 role description, "(midwife)". */
    private const DESCRIPTION = '/^\((.*)\)$/s';

    /** Values whose spelling changed. */
    private const RENAMED = ['PRE-1970' => 'PRE_1970', 'DNS/CAN' => 'DNS_CAN'];

    /** Words for RELA, lowercase => 7.0 role. */
    private const ROLES = [
        'child' => 'CHIL', 'son' => 'CHIL', 'daughter' => 'CHIL', 'clergy' => 'CLERGY', 'priest' => 'CLERGY',
        'father' => 'FATH', 'godfather' => 'GODP', 'godmother' => 'GODP', 'godparent' => 'GODP',
        'husband' => 'HUSB', 'mother' => 'MOTH', 'neighbor' => 'NGHBR', 'neighbour' => 'NGHBR',
        'officiant' => 'OFFICIATOR', 'officiator' => 'OFFICIATOR', 'parent' => 'PARENT', 'spouse' => 'SPOU',
        'wife' => 'WIFE', 'witness' => 'WITN',
    ];

    public function needsPrepass(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['ADOP', 'EVEN', 'MEDI', 'PEDI', 'QUAY', 'RELA', 'RESN', 'ROLE', 'SEX', 'STAT', 'TYPE'];
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        if (isset(self::SAME[$document->tagOf($id)][(string) $document->payloadOf($id)]) && !$document->hasChildren($id)) {
            return true;
        }
        $element = $context->sourceElement($id);
        if ($element === null || !isset(self::ELEMENTS[$element])) {
            return true;
        }
        [$set, $phrases] = self::ELEMENTS[$element];
        $value = $context->value($id);
        if ($element === '<RELATION_IS_DESCRIPTOR>') {
            $document->setTag($id, 'ROLE');
            if ($value === null || $value === '') {
                // 7.0 ROLE needs a value; an empty RELA says nothing more than OTHER.
                $context->setValue($id, 'OTHER');

                return true;
            }
        } elseif ($element === '<SOURCE_MEDIA_TYPE>' && $document->tagOf($id) === 'TYPE') {
            $document->setTag($id, 'MEDI');
        }
        if ($value === null || $value === '') {
            return true;
        }
        $permitted = Profile::for(Version::V7_0)->enumeration(self::SET . $set);
        $enum = strtoupper(trim($value));
        $enum = self::RENAMED[$enum] ?? $enum;
        $phrase = null;
        $list = isset(self::LISTS[$element]);
        if ($list) {
            $enum = implode(', ', array_map(static fn(string $item): string => self::RENAMED[$item] ?? $item, PayloadGrammar::listItems($enum)));
        }
        if ($element === '<RELATION_IS_DESCRIPTOR>' && !$this->permits($enum, $permitted, false)) {
            // Written like a 5.5.1 role description, "(godmother)", by some programs.
            $phrase = preg_match(self::DESCRIPTION, trim($value), $m) === 1 ? $m[1] : $value;
            $enum = self::ROLES[strtolower(trim($phrase))] ?? 'OTHER';
        } elseif ($element === '<ROLE_IN_EVENT>' && preg_match(self::DESCRIPTION, $value, $m) === 1) {
            [$enum, $phrase] = ['OTHER', $m[1]];
        } elseif (!$this->permits($enum, $permitted, $list)) {
            if (!$phrases || !in_array('OTHER', $permitted, true)) {
                return true;
            }
            [$enum, $phrase] = ['OTHER', $value];
        }
        if ($enum !== $value) {
            $context->setValue($id, $enum);
        }
        if ($phrase !== null && $phrase !== '') {
            $context->addText($id, 'PHRASE', $phrase);
        }

        return true;
    }

    /** @param list<string> $permitted */
    private function permits(string $value, array $permitted, bool $list): bool
    {
        foreach ($list ? PayloadGrammar::listItems($value) : [$value] as $item) {
            if (!PayloadGrammar::isEnumValue($item, $permitted)) {
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Extension;

/**
 * Programs known by the system identifier they write in HEAD.SOUR. Identifiers are those of
 * Tamura Jones, "GEDCOM System Identifiers" (https://www.tamurajones.net/GEDCOMSystemIdentifiers.xhtml);
 * they are compared without case, and a program may write several (HEREDIS PC2018, PAF 2.31).
 */
enum Program: string
{
    case Ancestry = 'Ancestry';
    case AncestralQuest = 'Ancestral Quest';
    case BrothersKeeper = "Brother's Keeper";
    case FamilyHistorian = 'Family Historian';
    case FamilyTreeMaker = 'Family Tree Maker';
    case GEDitCOM = 'GEDitCOM';
    case GeneWeb = 'GeneWeb';
    case GenoPro = 'GenoPro';
    case Gramps = 'Gramps';
    case Heredis = 'Heredis';
    case Legacy = 'Legacy Family Tree';
    case MacFamilyTree = 'MacFamilyTree';
    case MyHeritage = 'MyHeritage';
    case PersonalAncestralFile = 'Personal Ancestral File';
    case Reunion = 'Reunion';
    case RootsMagic = 'RootsMagic';
    case Webtrees = 'webtrees';

    private const IDENTIFIERS = [
        'ANCESTRY' => self::Ancestry,
        'ANCESTRY.COM FAMILY TREES' => self::Ancestry,
        'ANCESTQUEST' => self::AncestralQuest,
        'EFT' => self::AncestralQuest,
        'BROSKEEP' => self::BrothersKeeper,
        'FAMILY_HISTORIAN' => self::FamilyHistorian,
        'FTM' => self::FamilyTreeMaker,
        'FTW' => self::FamilyTreeMaker,
        'GEDITCOM' => self::GEDitCOM,
        'GENEWEB' => self::GeneWeb,
        'GENOPRO' => self::GenoPro,
        'GRAMPS' => self::Gramps,
        'LEGACY' => self::Legacy,
        'SYNIUMFAMILYTREE' => self::MacFamilyTree,
        'MACFAMILYTREE' => self::MacFamilyTree,
        'MYHERITAGE' => self::MyHeritage,
        'REUNION' => self::Reunion,
        'ROOTSMAGIC' => self::RootsMagic,
        'WEBTREES' => self::Webtrees,
    ];

    /** The program of a HEAD.SOUR value; null for one not in the table. */
    public static function fromIdentifier(string $identifier): ?self
    {
        $identifier = strtoupper(trim($identifier));

        return self::IDENTIFIERS[$identifier] ?? match (true) {
            // PAF writes its version into the identifier too: "PAF 2.31", "PAF 5.0".
            (bool) preg_match('/^PAF(?: [0-9.]+)?$/', $identifier) => self::PersonalAncestralFile,
            // Heredis makes a new identifier for each version: "HEREDIS 6.1 PC", "HEREDIS PC2018".
            str_starts_with($identifier, 'HEREDIS') => self::Heredis,
            default => null,
        };
    }
}

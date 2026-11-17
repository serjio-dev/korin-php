# Vendor dialects

Programs write GEDCOM with mistakes of their own. Korin knows the program that wrote a file
from `HEAD.SOUR` (`$doc->sourceSystem()`: the system identifier, `VERS`, `NAME` and the
`Extension\Program` when the identifier is known) and corrects the mistakes that are
documented. A correction only decides the encoding or the version a file is read in: no line
changes, preserve mode writes the file as it was, and each correction is reported as `KV1010`
(info). `ReadOptions::withDialects(false)` turns them off.

```php
$doc = Gedcom::read('family.ged');
$doc->sourceSystem()?->program();   // Program::PersonalAncestralFile
$doc->sourceSystem()?->version();   // "5.2.18.0"
```

## Corrections

| Program | Mistake | Read as | Source |
| --- | --- | --- | --- |
| Personal Ancestral File 5 (`PAF`, `VERS` 5 or later) | Writes GEDCOM 5.5.1 (UTF-8, `EMAIL`) and declares `5.5` | 5.5.1 | [1], [2] |
| Brother's Keeper before 6.1.12 (`BROSKEEP`) | Writes `CHAR IBMPC` for Windows-1252 | Windows-1252, when the content is not ASCII | [1] |
| GEDitCOM; MacFamilyTree before 8.3.5 | Writes `CHAR ASCII` with `2 VERS MacOS Roman` (`2 VERS MACINTOSH`) for MacRoman | MacRoman, when the content is not ASCII | [1] |

Without a `VERS` under `HEAD.SOUR`, Brother's Keeper is taken to be older than 6.1.12: from
6.1.12 on it writes `CHAR ASCII` instead.

## CHAR values

Besides the values of the specifications (`ANSEL`, `ASCII`, `UNICODE`, `UTF-8`), these values of
`HEAD.CHAR` are read as the encoding they name, with `KV1101`. Upper and lower case, spaces,
`-` and `_` do not matter.

| `HEAD.CHAR` | Encoding | Written by [1] |
| --- | --- | --- |
| `ANSI`, `WINDOWS`, `IBM WINDOWS`, `CP1252`, `WINDOWS-1252`, `LATIN1`, `ISO-8859-1`, `ISO8859` | Windows-1252 (Windows-1251 when the content is Cyrillic) | Parentele, EasyTree, Reunion, TribalPages, Lifelines, GenealogyJ, Genealogica Grafica |
| `WINDOWS-1251`, `CP1251`, `CYRILLIC` | Windows-1251 | Rodovid |
| `WINDOWS-1250`, `CP1250` | Windows-1250 | GenoPro, Rodokmen Pro |
| `IBMPC`, `IBM`, `IBM-PC`, `OEM`, `CP437` | CP437 | Reunion, Cumberland Family Tree, Généatique |
| `MSDOS`, `MS-DOS`, `IBM DOS`, `CP850` | CP850 | AbrEdit, Family Tree Maker for Windows, Reunion, EasyTree |
| `MACINTOSH`, `MACROMAN` | MacRoman | MacFamilyTree from 8.3.5 |

When the content contradicts `CHAR` (UTF-8 under `CHAR ANSEL`, 8-bit bytes under `CHAR ASCII`),
the content wins (`KV1102`); see the README.

## Programs

`Extension\Program` knows the identifiers of the table of Tamura Jones [3], compared without
case: Ancestry (`ANCESTRY`, `Ancestry.com Family Trees`), Ancestral Quest (`AncestQuest`,
`EFT`), Brother's Keeper (`BROSKEEP`), Family Historian (`FAMILY_HISTORIAN`), Family Tree Maker
(`FTM`, `FTW`), GEDitCOM, GeneWeb, GenoPro, Gramps, Heredis (`HEREDIS`, and the identifier of each
version: `HEREDIS PC2018`), Legacy, MacFamilyTree (`SyniumFamilyTree`), MyHeritage
(`MYHERITAGE`), Personal Ancestral File (`PAF`, `PAF 2.31`), Reunion, RootsMagic, webtrees.
A program not in the table has a `SourceSystem` without a `Program`.

Extension tags (`_UID`, `_APID`, `_FSFTID`, …) are kept whoever wrote them.

[1]: https://www.tamurajones.net/GEDCOMCharacterEncodings.xhtml "Tamura Jones, GEDCOM Character Encodings"
[2]: https://www.tamurajones.net/GEDCOMVersionDetection.xhtml "Tamura Jones, GEDCOM Version Detection"
[3]: https://www.tamurajones.net/GEDCOMSystemIdentifiers.xhtml "Tamura Jones, GEDCOM System Identifiers"

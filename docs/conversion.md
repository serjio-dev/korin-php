# Conversion rules

What `Gedcom::convert()` changes, by version pair. Conversions run along the chain
4.0 → 5.5 ↔ 5.5.1 ↔ 7.0, with 5.5.5 attached to 5.5.1 (SPEC section 7); output conventions and loss
codes are in [losses.md](losses.md).

```php
$result = Gedcom::convert(Gedcom::read('family.ged'), Version::V7_0);
file_put_contents('family-70.ged', $result->output());   // the converted file
$document = $result->document();                          // read from the output on demand
```

## 5.5.1 → 7.0

Upgrading loses nothing: what 7.0 has no structure for stays as it is (an extension).
Implemented so far:

| 5.5.1 | 7.0 |
| --- | --- |
| Any encoding, `HEAD.CHAR` | UTF-8 with a BOM, `CHAR` removed |
| `HEAD.GEDC.FORM` | Removed (7.0 is always lineage-linked) |
| `HEAD.FILE` | Removed: like `CHAR`, it describes the file, not its data (SPEC section 7, "Source housekeeping structures") |
| `CONC` | Joined into one line: 7.0 has no line length limit; `CONT` stays |
| `@@` anywhere in text | Only a leading `@` is doubled: `a@@b` → `a@b`, `@@ x` stays |
| `@#…@` in text | Kept as text: `@#DJULIAN@ x` → `@@#DJULIAN@ x`. In a 5.5.1 `DATE` it is a calendar escape, which the date rule converts; elsewhere (a `DATE` inside an extension) it is text like any other |
| Xref with anything but `A–Z`, `0–9`, `_`, or `@VOID@` (`@fam 1@`, `@note@`) | `@X<n>@`, the next free `n` in record order; every pointer follows. Valid xrefs (`@1@`, `@I1@`) are kept |
| Pointer to a record that does not exist, compared case-sensitively (`@test@` ≠ `@TEST@`) | `@VOID@`, without a loss entry: the source already has `KV4001` and the name of a missing record is not data |
| `NOTE` whose pointer leads nowhere (`NOTE @Smith family@`: text written without `@@`) | Text: `NOTE @@Smith family@` |
| `NOTE` record, `NOTE` pointer | `SNOTE`; a pointer where 7.0 has no `SNOTE` (under `FONE`, `ROMN`) stays `NOTE`, made `_NOTE` below |
| A structure 7.0 does not have where it stands: `NOTE`, `SOUR` of a `FONE` or `ROMN` (a 7.0 `TRAN` has neither) | An extension with its tag and substructures (`_NOTE`, `_SOUR`), `KL2001`; converting down gives the structure back |
| `SUBN` record, `HEAD.SUBN` | `HEAD._SUBN` with the record's substructures, one per `SUBN`; the record and the pointer are removed |
| `REPO` of a source without a pointer (`1 REPO` + `CALN`) | `REPO @VOID@`: 7.0 requires the pointer |
| `EVEN Moved to Osaka` of an individual or family without `TYPE` | `TYPE Moved to Osaka` added first: 7.0 requires `TYPE`, and the value describes the event. `FACT` and `IDNO` without `TYPE` stay so (`KV2003` in the output): nothing names their type |
| `OBJE` without a pointer | A new `OBJE` record `@X<n>@` (before `TRLR`, in order of appearance) with its substructures; the link keeps `TITL`. Not inside an extension, at any depth (`_MILT.SOUR.OBJE`) |
| `SOUR` with a description instead of a pointer | A new `SOUR` record `@X<n>@` with the description as `NOTE`; `TEXT` moves under a new `DATA` of the citation, `NOTE`, `QUAY` and the rest stay on it |
| `AFN x` | `EXID x` + `TYPE https://gedcom.io/terms/v7/AFN` |
| `RFN xyz:123abc`, `RFN x` | `EXID xyz` + `TYPE https://gedcom.io/terms/v7/RFN#123abc` (the conversion the 7.0 term file recommends), `EXID x` + `TYPE https://gedcom.io/terms/v7/RFN` |
| `RIN x` | `EXID x` + `TYPE https://gedcom.io/terms/v7/RIN#<HEAD.SOUR>` (percent-encoded; no fragment without `HEAD.SOUR`) |
| `_UID` | `UID`, value unchanged |
| Calendar escape in `DATE` (`@#DJULIAN@`, `@#DFRENCH R@`) | Calendar name (`JULIAN`, `FRENCH_R`); `@#DROMAN@`, `@#DUNKNOWN@` → `_ROMAN`, `_UNKNOWN` with extension months (`_ROMAN 24 _IUL 15`) |
| `B.C.` | `BCE` |
| Dual year `30 JAN 1648/49` | The year by a 1 January new year, the original as `PHRASE`: `DATE 30 JAN 1649` + `PHRASE 30 JAN 1648/49`, as in the 7.0 appendix; `1701/99` (not the following year) keeps the first year |
| `INT 1900 (text)`, `(text)` | `DATE 1900` + `PHRASE text`, `DATE` without a payload + `PHRASE text`; the whole value as `PHRASE` when the date also had a dual year |
| A `DATE` that is not a 5.5.1 date | Kept when it is a 7.0 date (`JULIAN 1650`, written without the escape), otherwise `PHRASE` of an empty `DATE`. Keywords and months in uppercase; `CHAN.DATE` and `HEAD.DATE`, which have no `PHRASE` in 7.0, only change case |
| `AGE` | 7.0 form: `<0Y` → `< 0y`, `11m 99y` → `99y 11m`, a bare number is years; `CHILD`, `INFANT`, `STILLBORN` → `< 8y`, `< 1y`, `0y` + `PHRASE`; anything else `PHRASE` of an empty `AGE` |
| `LANG` name (`Ukrainian`, any case) | BCP 47 (`uk`); a value that is already a tag stays; anything else `und` + `_PHRASE` (7.0 `LANG` has no `PHRASE`) |
| Media `FORM` (`jpg`) | Media type (`image/jpeg`); an unknown extension `application/x-<ext>` |
| `FORM.TYPE` (medium of a file) | `FORM.MEDI` |
| `FILE` | URL or relative path: `/x` → `file:///x`, `c:\x` → `file:///c:/x`, `\\server\x` → `file://server/x`, `\` → `/`; spaces, `%`, `?`, `#`, `[`, `]` percent-encoded. A URI with a scheme (`https://…`, `urn:isbn:…`) only has spaces and such encoded, `file://C:\x` → `file:///C:/x`. `..` stays, though 7.0 does not allow it |
| Enumerations (`PEDI birth`, `SEX f`, `STAT PRE-1970`, `DNS/CAN`) | 7.0 values (`BIRTH`, `F`, `PRE_1970`, `DNS_CAN`); a value 7.0 does not have becomes `OTHER` + `PHRASE` where 7.0 has both (`NAME.TYPE Screen`, `MEDI document`, `ROLE (midwife)`), and stays otherwise. `SOUR.DATA.EVEN birt,deat` → `BIRT, DEAT` |
| `FONE`, `ROMN` (of a name or place) | `TRAN` with `LANG` from the `TYPE`: `hangul` → `ko-Hang`, `kana` → `ja-Kana`, `romaji` → `ja-Latn`, `pinyin` → `zh-Latn-pinyin`, `wadegiles` → `zh-Latn-wadegile`; a `FONE` type only for `FONE`, a `ROMN` type only for `ROMN`; another `TYPE` stays as `_TYPE`, with `LANG und` (`FONE`) or `und-Latn` (`ROMN`); an empty `TYPE` is dropped |
| `RESI`, `EVEN` without a value | Unchanged: 7.0 requires the payload, but an empty `Text` is one (`1 EVEN` in section 1 of the specification); without substructures too, as below |
| An event with neither a value nor substructures (`1 BURI`, `1 DEAT`) | `Y`: it says the event occurred, and 7.0 has no structure without both |
| Any other structure with neither (`1 BAPL`, `1 SLGS`, an empty `SOUR.DATA`, `1 RESI`) | An extension (`_BAPL`, `_DATA`), `KL3001`, as in 5.5.1 → 5.5.5; converting down gives the structure back |
| — | `HEAD.SCHMA` lists the extension tags the conversion writes with a standard meaning: `_PHRASE https://gedcom.io/terms/v7/PHRASE`, unless the input already has `_PHRASE`; added to an existing `SCHMA`. Known from the prepass, so `HEAD` is complete when it is converted |
| `_NO`, `_SDATE`, `_CREA`, `_SCHMA`, `_PHRASE` and other extensions named after a 7.0 structure, where 7.0 has it and 5.5.1 does not; `_FAMC @VOID@` and the like; `SEX U` + `_SEX X` | The 7.0 structure (`NO`, `SDATE`, `SEX X`): how the conversion to 5.5.1 writes it. An extension 5.5.1 has the structure for (`_DATE` under `BIRT`) stays |
| `ASSO.RELA` | `ROLE`: a 7.0 role (`friend` → `FRIEND`), a common word with `PHRASE` (`Godmother` → `GODP`, `neighbor` → `NGHBR`), otherwise `OTHER` + `PHRASE` |

`HEAD.SOUR` names the program and is not a source; inside extensions (`_X`, `_SUBN`) nothing
is converted but the line syntax. Values are converted by the 5.5.1 structure they belong to
(`DATE` in `CHAN` is an exact date, `TYPE` under `FORM` a medium), not by tag alone.
Extensions of the input are kept as they are and are not added to `HEAD.SCHMA`: Korin does not
guess what a program meant by them. Nor are `_SUBN` and `_TYPE`, which keep 5.5.1 structures
7.0 has no standard URI for.

Not everything the gedcom7code converter does is done here: it strips `@#…@` escapes from
text (`obsolete @#OLD FORM@ escape` → `obsolete escape`), which drops data, and renames valid
xrefs (`@1@` → `@S1@`); it drops `SUBN` and its `RIN`, and puts extracted records before the
record that points to them. It converts standard tags inside extensions (`LANG` under `_ALL_LANGUAGES`), which 7.0
gives their standard meaning but 5.5.1 does not, documents extensions of the
input it knows (`_LOC`) in `HEAD.SCHMA`, and reads `1701/99` as 1699 (Korin keeps 1701: the second part is
not the following year, so it is not a new-year dual date) and writes Dogri as `dgr`, which is
Dogrib (Korin: `doi`). Where the two agree, its 7.0 file is the corpus golden file.

## 7.0 → 5.5.1

Downgrading drops nothing by default: what 5.5.1 has no structure for becomes an extension
and a [loss report](losses.md) entry. Converting the result up again gives the 7.0 document
back, except for what the loss report lists. Implemented so far:

| 7.0 | 5.5.1 |
| --- | --- |
| UTF-8 | UTF-8, `HEAD.CHAR UTF-8`, no BOM |
| — | `HEAD.GEDC.FORM LINEAGE-LINKED`, after `GEDC.VERS` |
| No `HEAD.SOUR` | `1 SOUR Korin` first in `HEAD`: 5.x requires the system that wrote the file (as in every conversion to 5.x) |
| Text with `@` | Every `@` doubled: `a@b` → `a@@b`. `@#DJULIAN@` in a 7.0 text is text, written `@#DJULIAN@` again (converting up reads it as text too) |
| Lines of any length | Split into `CONC` at 255 bytes, between characters, never before a space |
| `SNOTE` record, `SNOTE` pointer | `NOTE`; `HEAD.SNOTE` stays as `_SNOTE` (`KL2001`): 5.5.1 `HEAD.NOTE` is text, never a pointer |
| `HEAD._SUBN` | A `SUBN` record `@X<n>@` before `TRLR` with its substructures; `HEAD.SUBN` points to the first in its place |
| `UID` | `_UID` |
| `EXID` + `TYPE https://gedcom.io/terms/v7/AFN` | `AFN` |
| `EXID xyz` + `TYPE https://gedcom.io/terms/v7/RFN#123abc`, `…/RFN` | `RFN xyz:123abc`, `RFN xyz` (not for a value with `:`) |
| `EXID` + `TYPE https://gedcom.io/terms/v7/RIN#<HEAD.SOUR>` | `RIN` |
| Any other `EXID`, or one on a record without that tag in 5.5.1 | `_EXID` with its `TYPE`, `KL2001`. Not `REFN`: a number the user assigned is another kind of identifier, and it would stay `REFN` when converted up |
| A pointer to `@VOID@` (`FAMC @VOID@` + `PHRASE`) | An extension with its tag and substructures (`_FAMC @VOID@`), `KL4001` |
| `SLGC` with `FAMC @VOID@` (5.5.1 requires the family) | `_SLGC` with its substructures in their 7.0 form, `KL4001` |
| A record `HEAD.SCHMA` defines as a standard one (`TAG _USER https://gedcom.io/terms/v7/record-SUBM`) | That record: `0 @U1@ _USER` → `0 @U1@ SUBM` |
| `RESI Kyiv` (5.5.1 `RESI` has no value) | `RESI` + `NOTE Kyiv`, `KL3005` |
| `REPO @VOID@` of a source (a repository known by its call numbers) | `REPO` without a pointer, which 5.5.1 allows |
| `EVEN` with a `TYPE` equal to its value (how 5.5.1 → 7.0 writes an `EVEN` without `TYPE`) | The `TYPE` removed |
| Calendar names in `DATE` (`JULIAN`, `FRENCH_R`, `_ROMAN 24 _IUL 15`) | Escapes (`@#DJULIAN@`, `@#DFRENCH R@`, `@#DROMAN@ 24 IUL 15`); `BCE` → `B.C.` |
| `DATE` + `PHRASE` | One 5.5.1 date: the `PHRASE` when it is the date it came from (`30 JAN 1649` + `PHRASE 30 JAN 1648/49` → `30 JAN 1648/49`), otherwise `INT 5 MAR 1900 (phrase)`, or `(phrase)` without a date. A `PHRASE` of an approximate date or a range stays |
| A date 5.5.1 cannot write (an extension calendar) | `_DATE`, `KL3001` |
| `TIME 15:43:20Z` (5.5.1 times have no time zone) | `_TIME`, `KL3001` |
| `AGE` | `< 8y` → `<8y`; `< 8y` + `PHRASE CHILD` → `CHILD`. An age in weeks, or a `PHRASE` alone that is no 5.5.1 age (`AGE` + `PHRASE Zero`) → `_AGE`, `KL3001` |
| `HEAD.LANG`, `SUBM.LANG` | 5.5.1 names: `uk` → `Ukrainian`, `und` + `_PHRASE Hmong` → `Hmong`. A tag without a 5.5.1 name (`en-GB`) → `_LANG`, `KL3001`. A `SUBM.LANG` after the three 5.5.1 allows keeps its tag (`_LANG fr`, `KL2002`) |
| Media `FORM` | A file extension: one 5.5.1 lists (`image/jpeg` → `jpg`, `image/tiff` → `tif`), another known one (`image/png` → `png`), `application/x-x` → `x`; others stay |
| `FORM.MEDI` | `FORM.TYPE` |
| `FILE` | A path: `file:///c:/My%20Photos/a.jpg` → `c:\My Photos\a.jpg`, `file:///x` → `/x`, `file://server/x` → `\\server\x`, a relative path without percent-encoding. Other URLs stay |
| Enumerations | 5.5.1 case and spelling (`BIRTH` → `birth`, `PRE_1970` → `PRE-1970`, `DNS_CAN` → `DNS/CAN`); `OTHER` + `PHRASE` → the phrase (`(midwife)` for a role in an event) |
| A value a closed 5.5.1 list does not have (`MEDI OTHER` + `PHRASE Portrait`: `FORM.TYPE` lists `photo`, `book`…; `STAT INFANT`) | The 7.0 structure as an extension (`_MEDI OTHER` + `PHRASE Portrait`, `_STAT INFANT`), `KL3001` |
| `ASSO.ROLE` | `RELA`: `GODP` + `PHRASE godmother` → `godmother`, `FRIEND` → `Friend`, `OTHER` + `PHRASE land-lord` → `land-lord` |
| `TRAN` of a name or place | `FONE`/`ROMN` with `TYPE` where 5.5.1 has the type: `ko-Hang` → `FONE hangul`, `ja-Kana` → `FONE kana`, `ja-Latn` → `ROMN romaji`, `zh-Latn-pinyin` → `ROMN pinyin`, `zh-Latn-wadegile` → `ROMN wadegiles`; `und`/`und-Latn` + `_TYPE x` → `FONE`/`ROMN` + `TYPE x`. Another language → `_TRAN`, `KL2001` |
| `SEX X` | `SEX U` + `_SEX X`, `KL3002` |
| A structure 5.5.1 does not have where it stands: `NO`, `SDATE`, `CREA`, `INIL`, `CROP`, `HEAD.SCHMA`, `MIME`/`LANG` of a note, `ASSO` of an event, a `PHRASE` no value could take | An extension with its tag and substructures (`_NO`, `_SDATE`, `_SCHMA`), `KL2001`; converting up gives the structure back |
| `_NOTE`, `_SOUR` and other extensions named after a 5.5.1 structure, where 5.5.1 has it and 7.0 does not (under `TRAN`), or that 7.0 cannot write empty (`_BAPL`) | The 5.5.1 structure: how the conversion to 7.0 writes it. An extension 7.0 has the structure for stays |
| More of a structure than 5.5.1 allows (a second `RFN` of a record) | The extra ones as extensions, `KL2002` (an `EXID` that would be a second `RFN` stays `_EXID`, `KL2001`) |
| `Y` of an event 5.5.1 writes without a value (`BURI Y`, `CREM Y`) | No value: an event without substructures means the same, and converting up gives `Y` back; one with substructures is reported, `KL3003` |

Every value is chosen so that converting it up gives the same 7.0 value and `PHRASE` again
(so `ROLE GODP` without a phrase is `RELA Godp`: `Godparent` would come back with a
`PHRASE`). Where no 5.5.1 value does, the structure is kept as an extension with a loss entry,
or, for a `PHRASE` alone, left to the rule for structures 5.5.1 does not have. Values 5.5.1
programs write although 5.5.1 does not list them stay as they are (`PEDI guardian`,
`FORM png`, `RESN confidential, locked`).

Xrefs are kept: every 7.0 xref is a valid 5.5.1 one. Extensions (`_X`) and what is inside them
only get the 5.5.1 line syntax.

## 5.5.1 → 5.5

5.5 is written in UTF-16 (big-endian, with a BOM, `HEAD.CHAR UNICODE`). Nothing is dropped
by default; converting the result up again gives the 5.5.1 document back, except for what the
loss report lists with a `KL3` code.

| 5.5.1 | 5.5 |
| --- | --- |
| A structure 5.5 does not have where it stands: `EMAIL`, `FAX`, `WWW`, `ADR3`, `MAP`, `FONE`, `ROMN`, `NAME.TYPE`, `FACT`, `FAMC.STAT`, `RELI` and `RESN` of an event, `RESN` of a family, `MEDI` | An extension with its tag and substructures (`_EMAIL`, `_MAP`), `KL2001` |
| More of a structure than 5.5 allows: a second `REPO` of a source, a second `FILE` of a media link | The extra ones as extensions (`_REPO`, `_FILE`), `KL2002` |
| `REPO` of a source without a pointer (`1 REPO` + `CALN`) | `_REPO`, `KL3001`: 5.5 requires the pointer |
| A value 5.5 does not list: `SEX U`, `RESN confidential`, `STAT EXCLUDED` | The structure as an extension with its value (`_SEX U`), `KL3001` |
| Media `FORM jpg`, `tif` | `jpeg`, `tiff`, the 5.5 spelling |
| `EVEN Served in the navy` | 5.5 `EVEN` has no value; it describes the event with `TYPE`, the element 5.5.1 writes in the value: `2 TYPE Served in the navy` (`KL3004`, `moved`). An `EVEN` with a `TYPE` already: `2 NOTE Served in the navy` (`KL3004`, `note`) |
| Media link: `OBJE` + `FILE` + `FORM` | `FORM` belongs to the `OBJE`: the `FORM` of the first `FILE` moves up, before it |
| Media record: `FILE a.jpg` + `FORM` + `TITL` | A 5.5 record has no `FILE`: it holds the object itself (`BLOB`). The `FORM` and `TITL` of the first `FILE` move up to the record, the file is `_FILE a.jpg` (`KL2001`). The 5.5 validator reports the missing `BLOB` (`KV2003`) |
| `ASSO` | `TYPE INDI` added as its first substructure: 5.5 requires it |
| `BIRT Y` and the other events | Unchanged: 5.5 allows `Y` on every event |

## 5.5 → 5.5.1

Upgrading keeps everything: what 5.5.1 has no structure for becomes an extension and a loss
report entry.

| 5.5 | 5.5.1 |
| --- | --- |
| Any encoding | UTF-8, `HEAD.CHAR UTF-8` |
| A structure 5.5.1 does not have where it stands: `BLOB` and `OBJE` (continuation) of a media record, `NOTE` of a media link, `SOUR` of a note or a place, `AGE` of a family event | An extension with its tag and substructures (`_BLOB`, `_AGE`), `KL2001` |
| A second `PEDI` of a `FAMC` | `_PEDI`, `KL2002` |
| `STAT CLEARED`, `QUALIFIED`, `INFANT` where 5.5.1 does not list it | `_STAT`, `KL3001` |
| `Y` of an event 5.5.1 writes without a value (`BURI Y`, `DIV Y`) | No value, as 7.0 → 5.5.1 (`KL3003` with substructures) |
| Media `FORM jpeg`, `tiff` | `jpg`, `tif` |
| Media link: `OBJE` + `FORM` + `FILE` | `FORM` moves into the `FILE` |
| Media record with `_FILE` (as 5.5.1 → 5.5 writes it) or a `FILE` (as some 5.5 programs write) | A `FILE` with the record's `FORM` and `TITL`. A record without either keeps them as `_FORM`, `_TITL` (`KL2001`); the 5.5.1 validator reports the missing `FILE` |
| `ASSO` + `TYPE INDI` | `TYPE` removed; an association with another record (`TYPE FAM`) → `_ASSO`, `KL2001` |
| `_EMAIL`, `_MAP`, `_SEX U` and other extensions 5.5.1 could write as the structure it has there | The structure (`EMAIL`, `MAP`, `SEX U`): how 5.5.1 → 5.5 writes it |

A conversion between 5.5 and 7.0 goes through 5.5.1: 7.0 → 5.5 is 7.0 → 5.5.1 and then
5.5.1 → 5.5, with one loss report for both, located in the input. Converting a 7.0 file to 5.5
and back gives the same fingerprint, except for `KL3` losses, as it does through 5.5.1 (tested
on every 7.0 input of the corpus).

A structure 5.5.1 does not have where it stands (5.5 and 7.0 `NOTE.SOUR`, 7.0 `FAM.NCHI.DATE`)
goes through it as an extension, and the next step makes it the structure again. Its value and
what is inside are still in the form of the version before 5.5.1. They are converted as the
5.5.1 structure of that tag that the value fits (`_DATE INT 1900 (about then)` → `DATE 1900` +
`PHRASE`), a 5.5 media link inside has its `FORM` moved into the `FILE`, and a value the target
does not list becomes an extension (7.0 `ROLE NGHBR` → 5.5 `_ROLE`, `KL3001`). The `KL2001` of the
intermediate extension is not reported: the output has the structure. Under `LossPolicy::Fail`
the first step still stops at it.
An extension of the input that a conversion makes the structure has its value converted so too
(5.5.1 `FAM.NCHI._DATE INT 1900 (about then)`, as 7.0 → 5.5.1 writes it, → 7.0 `DATE 1900` +
`PHRASE`). What is inside is in the target's form already.

## 4.0 → 5.5

GEDCOM 4.0 (1989) has a dictionary of tags, but no grammar of where they go, and writes values
in words. A 4.0 file (`HEAD.GEDC.VERS` `4.0`, `4.0.4.18` as PAF writes it, or `4+` as Roots and
Ultimate Family Tree do) is read as `Version::V4_0` and validated with the 5.5 profile (`KV1009`);
it is read only, so it converts to 5.5 and from there to any version. Best effort: the values
take the form 5.5 has for them, and nothing is reported for that, as with other spellings.

| 4.0 | 5.5 |
| --- | --- |
| `SEX Male`, `Female` | `M`, `F` |
| A date in words: month names (`11 June 1861`, `Sept.`), `Abt.`, `c.`, `Before`, `After`, `Between … and …` | The 5.5 date when it then fits 5.5: `11 JUN 1861`, `ABT 1850`, `BET 1880 AND 1882` |
| Any other date that does not fit 5.5, which 4.0 calls irregular and keeps as written (`4/5 January 1751/52`, `7-12-84`, `2 days after Easter, 1690`) | A date phrase: `(4/5 January 1751/52)` |
| A date with `@` (a 4.0 calendar escape) or a parenthesis | Stays; the 5.5 validator reports it |
| A structure 5.5 does not have where it stands (`HEAL`, `SYMB`) | An extension, `KL2001` |
| `HEAD` without `GEDC.FORM`, `CHAR IBMPC` | `GEDC.VERS 5.5`, `FORM LINEAGE-LINKED`, `CHAR UNICODE` |

5.5 requires a submitter, which a 4.0 file may not have; the conversion does not invent one.
The `+` continuation and the `@#L` and `@#C` escapes of 4.0 are not read: such lines are kept
as they are (`KV1001`).

A 5.0–5.4 draft (and `5.01`, the 5.1 of some programs) is read as 5.5, with `KV1009`.

## 5.5.1 → 5.5.5

5.5.5 is 5.5.1 made strict: UTF-8 with a BOM, `GEDC.FORM LINEAGE-LINKED` with its own
`VERS 5.5.5`, no inline sources or media, no structure with neither a value nor substructures,
and stricter grammars for values. Nothing is dropped by default; converting the result up
again gives the 5.5.1 document back, except for what the loss report lists with a `KL3` code,
the inline sources and media (records in 5.5.5), new `ADR` lines and respelled values.

| 5.5.1 | 5.5.5 |
| --- | --- |
| `HEAD.GEDC.FORM` | `FORM LINEAGE-LINKED` with `VERS 5.5.5`, added when missing |
| `HEAD.SUBM` and the `SUBM` records | The record `HEAD.SUBM` points to (or else the first; a missing `HEAD.SUBM` then points to it, unless `HEAD` has `_SUBM`, a pointer to no record) moves directly after `HEAD`; any other `SUBM` record → `_SUBM`, `KL2002`. A file without a `SUBM` record keeps none |
| `SUBN` record, `HEAD.SUBN` | `HEAD._SUBN` with the record's substructures, as 5.5.1 → 7.0 |
| Inline `SOUR Family Bible` + `TEXT` | A `SOUR` record `@X<n>@` with `TITL Family Bible`; the citation points to it, its `TEXT` under a new `DATA` |
| Inline `OBJE` + `FILE` + `FORM` + `MEDI` + `TITL` | An `OBJE` record `@X<n>@` with the `FILE`, `TITL` under the first `FILE`, `MEDI` as `FORM.TYPE`; a `FORM` beside `FILE` moves into it. A `FORM` 5.5.5 does not list (`ole`) stays (`KV3006`, see the matrix limits) |
| `ADDR 12 Main St` + `CONT apt. 4` | 5.5.5 `ADDR` has no value: its lines are `ADR1`–`ADR3`. Lines an `ADR` line repeats are dropped, missing `ADR` lines added. A value made of `CITY`, `STAE` `POST` and `CTRY` is dropped. Any other value (more than three lines, an empty line, a line other than its `ADR`) → a first substructure `_ADDR`, `KL3001` |
| A structure with neither a value nor substructures (`BURI`, `BIRT Y`: 5.5.5 has `Y` only for `MARR`, `CHR`, `DEAT`) | An extension (`_BURI`, `_BIRT Y`), `KL3001` |
| `SEX U` + `_SEX X` (7.0 → 5.5.1 writes it for `SEX X`) | `SEX X` |
| A structure 5.5.5 does not have where it stands: LDS ordinances, `RESN`, `ALIA`, `ANCI`, `DESI`, `AFN`, `RFN`, `HEAD.PLAC`, `HEAD.CHAR.VERS`, `SUBM.LANG`, `SOUR` of a note | An extension with its tag and substructures, `KL2001` |
| More than 5.5.5 allows: a second `FILE` of a media record, a second `CALN` | The extra ones as extensions, `KL2002` |
| Dates: `05 JAN 50`, `ABT 50` | Days without a leading zero, years of at least 3 digits: `5 JAN 050`, `ABT 050` |
| Ages: `<01y 0m` | `< 1y`: a space after `<` and `>`, no leading zeroes, no zero parts. An age with no part left (`0y`) → `_AGE`, `KL3001` |
| Times: `09:05:00.5` | `9:05:00.50` |
| Places: `Kyiv,Ukraine` | `Kyiv, Ukraine`; a place with an empty part (`Kyiv, , Ukraine`) stays as it is |
| A value no 5.5.5 spelling fits (`HEAD.SOUR.VERS 7.0 beta`) | The structure as an extension, `KL3001` |

Lengths (5.5.5 `{Size=min:max}`, `KV3010`) are not converted: a value too long or too short
for 5.5.5 is still its value. `FACT` and `IDNO` without the `TYPE` 5.5.5
requires get none: there is nothing to name it by.

## 5.5.5 → 5.5.1

| 5.5.5 | 5.5.1 |
| --- | --- |
| `GEDC.FORM.VERS` | Removed |
| `ADDR` without a value | The value: the first substructure `_ADDR` (removed), or else `ADR1`–`ADR3` as lines, or else `CITY, STAE POST, CTRY` |
| `HEAD._SUBN` | A `SUBN` record, as 7.0 → 5.5.1 |
| `_SUBM` records | `SUBM` records |
| `SEX X`, `SEX N` | `SEX U` + `_SEX X`, `_SEX N`, `KL3002` |
| Dates: `ABT 050`, `1000 BCE` | `ABT 50`, `1000 B.C.` |
| A day and month without a year (`5 MAR`) | `_DATE`, `KL3001`: 5.5.1 has no such date |
| Ages: `< 1y` | `<1y` |
| Media `FORM JPEG`, `TIFF` | `jpg`, `tif`; other formats 5.5.1 does not list (`PNG`, `PDF`) stay, as 5.5.1 programs write them |
| `_BURI`, `_BIRT Y`, `_DATE` and other extensions 5.5.1 could write as the structure it has there | The structure |

5.5.5 ↔ 7.0 goes through 5.5.1. A 7.0 file converted to 5.5.5 and back has the same
fingerprint, except for `KL3` losses and what 5.5.5 writes its own way: the `SUBM` records
directly after `HEAD` (with `HEAD.SUBM`), `ADR1`–`ADR3` lines that repeat the `ADDR` value, and
times without a leading zero (`8:56`) or with a two-digit fraction (`.50`), the same times in
7.0 (tested on every 7.0 input of the corpus).

## Conversion matrix

Every corpus input, and random valid documents of every version (`tests/Generative`), is
converted to every version (`tests/Corpus/MatrixTest.php`, SPEC section 10): the conversion
is deterministic, converting the output to its own version changes nothing, and the output
validates without errors other than those of the input and these limits of the target versions:

| Target | Error the output may have | Why |
| --- | --- | --- |
| 5.x | `HEAD requires SUBM` (`KV2003`; 5.5.5: the file requires a `SUBM` record) | 7.0 does not require a submitter, and the conversion does not invent one |
| 5.x | `FORM does not permit …` (`KV3006`) on a media `FORM` | 5.x lists a few formats from the 1990s; others (`png`, `pdf`) stay, as programs write them |
| 5.5, 5.5.1 | `RESN does not permit "confidential, locked"` (`KV3006`) | 5.x has one restriction; programs write the 7.0 list, and keeping one of them would lift a restriction |
| 5.5 | `OBJE requires BLOB` (`KV2003`) | A 5.5 media record holds the object itself; a file reference is `_FILE` (`KL2001`) |
| 5.5.5 | `… is longer than 248 characters`, `… is shorter than …` (`KV3010`) | Lengths are not converted: a 7.0 text has no limit, and cutting it would lose it; a short one (`EMAIL x`) is not padded |
| 7.0, 5.5.5 | `FACT requires TYPE`, `IDNO requires TYPE` (`KV2003`) | 5.5.1 does not require `TYPE`, and nothing names it |
| 7.0 | `EVEN requires TYPE` (`KV2003`) | An `EVEN` without a value has nothing to name its type with (one with a value gets it as `TYPE`) |
| 5.5.1, 5.5.5, 7.0, from 5.5 | `OBJE requires FILE` (`KV2003`) | The 5.5 media record holds the object itself (`BLOB`), which is not a file |
| 5.5.1, 5.5.5, 7.0, from 5.5 | `REPO requires NAME`, `STAT requires DATE` (`KV2003`) | 5.5 requires neither the name of a repository nor the date of an LDS status, and the conversion does not invent them |

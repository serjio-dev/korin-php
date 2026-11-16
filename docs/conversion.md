# Conversion rules

What `Gedcom::convert()` changes, by version pair. Conversions run along the chain
5.5 ↔ 5.5.1 ↔ 7.0, with 5.5.5 attached to 5.5.1 (SPEC section 7); output conventions and loss
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
| `NOTE` record, `NOTE` pointer | `SNOTE` |
| `SUBN` record, `HEAD.SUBN` | `HEAD._SUBN` with the record's substructures, one per `SUBN`; the record and the pointer are removed |
| `OBJE` without a pointer | A new `OBJE` record `@X<n>@` (before `TRLR`, in order of appearance) with its substructures; the link keeps `TITL` |
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
| `RESI`, `EVEN` without a value | Unchanged: 7.0 requires the payload, but an empty `Text` is one (`1 EVEN` in section 1 of the specification) |
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
| Text with `@` | Every `@` doubled: `a@b` → `a@@b`. `@#DJULIAN@` in a 7.0 text is text, written `@#DJULIAN@` again (converting up reads it as text too) |
| Lines of any length | Split into `CONC` at 255 bytes, between characters, never before a space |
| `SNOTE` record, `SNOTE` pointer | `NOTE` |
| `HEAD._SUBN` | A `SUBN` record `@X<n>@` before `TRLR` with its substructures; `HEAD.SUBN` points to the first in its place |
| `UID` | `_UID` |
| `EXID` + `TYPE https://gedcom.io/terms/v7/AFN` | `AFN` |
| `EXID xyz` + `TYPE https://gedcom.io/terms/v7/RFN#123abc`, `…/RFN` | `RFN xyz:123abc`, `RFN xyz` (not for a value with `:`) |
| `EXID` + `TYPE https://gedcom.io/terms/v7/RIN#<HEAD.SOUR>` | `RIN` |
| Any other `EXID`, or one on a record without that tag in 5.5.1 | `_EXID` with its `TYPE`, `KL2001`. Not `REFN`: a number the user assigned is another kind of identifier, and it would stay `REFN` when converted up |
| A pointer to `@VOID@` (`FAMC @VOID@` + `PHRASE`) | An extension with its tag and substructures (`_FAMC @VOID@`), `KL4001` |
| Calendar names in `DATE` (`JULIAN`, `FRENCH_R`, `_ROMAN 24 _IUL 15`) | Escapes (`@#DJULIAN@`, `@#DFRENCH R@`, `@#DROMAN@ 24 IUL 15`); `BCE` → `B.C.` |
| `DATE` + `PHRASE` | One 5.5.1 date: the `PHRASE` when it is the date it came from (`30 JAN 1649` + `PHRASE 30 JAN 1648/49` → `30 JAN 1648/49`), otherwise `INT 5 MAR 1900 (phrase)`, or `(phrase)` without a date. A `PHRASE` of an approximate date or a range stays |
| A date 5.5.1 cannot write (an extension calendar) | `_DATE`, `KL3001` |
| `AGE` | `< 8y` → `<8y`; `< 8y` + `PHRASE CHILD` → `CHILD`. An age in weeks → `_AGE`, `KL3001` |
| `HEAD.LANG`, `SUBM.LANG` | 5.5.1 names: `uk` → `Ukrainian`, `und` + `_PHRASE Hmong` → `Hmong`. A tag without a 5.5.1 name (`en-GB`) → `_LANG`, `KL3001` |
| Media `FORM` | A file extension: one 5.5.1 lists (`image/jpeg` → `jpg`, `image/tiff` → `tif`), another known one (`image/png` → `png`), `application/x-x` → `x`; others stay |
| `FORM.MEDI` | `FORM.TYPE` |
| `FILE` | A path: `file:///c:/My%20Photos/a.jpg` → `c:\My Photos\a.jpg`, `file:///x` → `/x`, `file://server/x` → `\\server\x`, a relative path without percent-encoding. Other URLs stay |
| Enumerations | 5.5.1 case and spelling (`BIRTH` → `birth`, `PRE_1970` → `PRE-1970`, `DNS_CAN` → `DNS/CAN`); `OTHER` + `PHRASE` → the phrase (`(midwife)` for a role in an event) |
| `ASSO.ROLE` | `RELA`: `GODP` + `PHRASE godmother` → `godmother`, `FRIEND` → `Friend`, `OTHER` + `PHRASE land-lord` → `land-lord` |
| `TRAN` of a name or place | `FONE`/`ROMN` with `TYPE` where 5.5.1 has the type: `ko-Hang` → `FONE hangul`, `ja-Kana` → `FONE kana`, `ja-Latn` → `ROMN romaji`, `zh-Latn-pinyin` → `ROMN pinyin`, `zh-Latn-wadegile` → `ROMN wadegiles`; `und`/`und-Latn` + `_TYPE x` → `FONE`/`ROMN` + `TYPE x`. Another language → `_TRAN`, `KL2001` |
| `SEX X` | `SEX U` + `_SEX X`, `KL3002` |
| A structure 5.5.1 does not have where it stands: `NO`, `SDATE`, `CREA`, `INIL`, `CROP`, `HEAD.SCHMA`, `MIME`/`LANG` of a note, `ASSO` of an event, a `PHRASE` no value could take | An extension with its tag and substructures (`_NO`, `_SDATE`, `_SCHMA`), `KL2001`; converting up gives the structure back |

Every value is chosen so that converting it up gives the same 7.0 value and `PHRASE` again
(so `ROLE GODP` without a phrase is `RELA Godp`: `Godparent` would come back with a
`PHRASE`). Where no 5.5.1 value does, the structure is kept as an extension with a loss entry,
or, for a `PHRASE` alone, left to the rule for structures 5.5.1 does not have. Values 5.5.1
programs write although 5.5.1 does not list them stay as they are (`PEDI guardian`,
`FORM png`, `RESN confidential, locked`).

Xrefs are kept: every 7.0 xref is a valid 5.5.1 one. Extensions (`_X`) and what is inside them
only get the 5.5.1 line syntax.

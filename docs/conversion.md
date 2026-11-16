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
| `CONC` | Joined into one line: 7.0 has no line length limit; `CONT` stays |
| `@@` anywhere in text | Only a leading `@` is doubled: `a@@b` → `a@b`, `@@ x` stays |
| `@#…@` in text | Kept as text: `@#DJULIAN@ x` → `@@#DJULIAN@ x`. In `DATE` it is a calendar escape, left to the date rule |
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

`HEAD.SOUR` names the program and is not a source; inside extensions (`_X`, `_SUBN`) nothing
is converted but the line syntax. Dates, ages, `LANG`, media values (`FORM`, `FILE`, `MEDI`),
`ASSO`/`RELA` and `HEAD.SCHMA` follow in 0.3.

Not everything the gedcom7code converter does is done here: it strips `@#…@` escapes from
text (`obsolete @#OLD FORM@ escape` → `obsolete escape`), which drops data, and renames valid
xrefs (`@1@` → `@S1@`); it drops `SUBN` and its `RIN`, and puts extracted records before the
record that points to them. Where the two agree, its 7.0 file is the corpus golden file.

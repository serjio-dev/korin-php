# Diagnostics reference

Every diagnostic has a stable code. A code never changes meaning within a major version;
new codes may be added in minor releases. Messages are informative and may change.

| Range | Level |
| --- | --- |
| `KV1xxx` | Syntax: line format, levels, encoding, HEAD/TRLR |
| `KV2xxx` | Structure: allowed substructures and cardinality |
| `KV3xxx` | Values: payload presence and data types, dates |
| `KV4xxx` | References: dangling and duplicate xrefs, record types, family links |
| `KV5xxx` | Plausibility, optional: dates and pedigrees that cannot be true |
| `KL0001`–`KL9999` | Loss report entries of conversions, see [losses.md](losses.md) |

## KV1 — syntax

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV1001` | warning (info for an empty line) | The line is not `level [xref] tag [value]`; it is kept verbatim and ignored by the model |
| `KV1002` | error | Level jumps by more than one; the line is attached to the deepest open structure |
| `KV1003` | error | Bytes are invalid in the detected encoding; the value is decoded with U+FFFD, the original bytes are kept for writing |
| `KV1004` | error | The first record is not `HEAD` |
| `KV1005` | error | `TRLR` is missing |
| `KV1006` | warning | Records follow `TRLR` |
| `KV1007` | warning | `HEAD.GEDC.VERS` is missing; the file is read as 5.5.1 |
| `KV1008` | warning | `HEAD.GEDC.VERS` names an unknown version; the file is read as 5.5.1 |
| `KV1009` | info | `HEAD.GEDC.VERS` names GEDCOM 4.0 (`4.0`, `4.0.4.18`, `4+`), read as `Version::V4_0`, or a 5.0–5.4 draft (`5.3`, `5.01`), read as 5.5: both with the 5.5 profile ([conversion.md](conversion.md#40--55)) |
| `KV1101` | warning | `HEAD.CHAR` is not a standard value (e.g. `ANSI`, `LATIN1`); the encoding is detected from content |
| `KV1102` | warning | `HEAD.CHAR` contradicts the content; the content wins |
| `KV1103` | info | No usable `HEAD.CHAR`; the encoding is guessed from content |
| `KV1104` | warning | 5.5.5: the file does not start with a byte order mark ("All GEDCOM 5.5.5 files … must start with a Byte Order Mark"). Reported by `Gedcom::validate()` for the file, without a line; a warning, because the BOM carries no data |
| `KV1201` | warning | GEDZIP: an entry name is not a safe relative path (`..`, an absolute path, `\`, a drive letter, a control character); the entry is ignored. Reported by `Gedcom::readGedzip()` in `Archive::diagnostics()` |
| `KV1202` | warning | GEDZIP: an entry is a symbolic link; it is ignored |
| `KV1203` | warning | GEDZIP: `gedcom.ged` is not GEDCOM 7.0; it is read as what it declares |
| `KV1204` | warning | GEDZIP: a `FILE` reference that is a relative path names no entry of the archive |

In strict mode (`ReadOptions::withStrict()`) every error-level `KV1` diagnostic throws
`ParseException` instead.

## KV2 — structure

Reported by `Gedcom::validate()` against the profile of the document's version. Extension tags
(`_X`) and everything under them are not checked. `reference` names the profile term of the
structure that contains the problem: a 7.0 term such as `https://gedcom.io/terms/v7/record-INDI`,
or for 5.x a place in the grammar of the specification such as
`https://gedcom.io/specifications/ged551.pdf#INDIVIDUAL_RECORD/INDI`
(5.5.5: `https://www.gedcom.org/specs/GEDCOM555.zip#…`).

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV2001` | error | A standard tag that is not allowed where it stands (in this superstructure, or as a record); its substructures are not checked |
| `KV2002` | error | A substructure occurs more often than allowed; reported once, at the first extra occurrence |
| `KV2003` | error | A required substructure is missing; reported at the structure that lacks it. A missing required record (5.5.5: `SUBM`) is reported for the file, without a line |
| `KV2004` | error | 5.5.5: the `SUBM` record does not directly follow `HEAD`; reported at the first `SUBM` record. Extension records do not count |

Every code can be left out or reported with another severity:

```php
$options = (new ValidationOptions())->withDisabled('KV2001')->withSeverity('KV1101', Severity::Error);
$report = Gedcom::validate($doc, $options);
```

## KV3 — values

Reported by `Gedcom::validate()` for every version against the payload of each standard
structure; extension structures are not checked. Messages name the expected type and never
quote a value, except an enumeration value shortened to 20 characters.

- 7.0: the data types of specification chapter 2. Dates follow the `DateValue`, `DatePeriod`
  and `DateExact` grammars with the months and epochs of their calendar (`GREGORIAN` when none
  is given; extension calendars must use extension months).
- 5.5 and 5.5.1: the primitive elements of `spec/<version>/primitives.yaml`; other elements are text, and
  field sizes are recommendations, not checked. Enumerations, date keywords and months are
  case-insensitive. Dates are `DATE_VALUE`, `DATE_PERIOD` or `DATE_EXACT` with calendar escapes
  (`@#DJULIAN@`), date phrases in parentheses, `B.C.` after any year (`44 B.C.`,
  `MAR 44 B.C.`) and Gregorian dual years (`1648/49`).
- 5.5.5: the primitive elements of `spec/5.5.5/primitives.yaml` with the stricter grammars of
  5.5.5: years of 3 or 4 digits, days without a leading zero, `BCE`, `BC` or `B.C.` after a
  lone Gregorian or Julian year, Gregorian and Julian dual years with a month, `23 AUG` without
  a year, no `@#DROMAN@`; ages with `< ` / `> ` and without zero parts or leading zeroes (at
  most `999y 11m 365d`); times `h:mm[:ss[.ff]]`; version numbers; place names in parts
  separated by `, `. Text values are checked against their `{Size=min:max}` (`KV3010`). The
  file must start with a BOM (`KV1104`) and `SUBM` directly follow `HEAD` (`KV2004`). Rules
  given only in prose (no digits in names, dual years only from 1 January to 24 March and up
  to 1923) are not checked.

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV3001` | error | A value is required but missing (7.0: `Text`, `List:Text`, `Age`, `DateValue`, `DatePeriod` and `Y\|<NULL>` may be omitted, as long as the structure has substructures (`KV3007`); 5.x: payloads with `<NULL>`) |
| `KV3002` | error | The structure must not have a value |
| `KV3003` | error | The value must be a pointer to a record |
| `KV3004` | error | A pointer where a value is expected (in 7.0 a leading `@` of text is written `@@`) |
| `KV3005` | error | The value does not match its data type. 7.0: integer, personal name, BCP 47 language, media type, age, time, latitude, longitude, file path, URI, tag definition, `Y`, dates (including a day outside 1–36). 5.x: age, time, number, personal name, `Y`, dates; 5.5.1 and 5.5.5 also latitude, longitude; 5.5.5 also version numbers and place names |
| `KV3006` | error | An enumeration value that the structure does not permit (5.5.5 also `HEAD.GEDC.FORM`, which must be `LINEAGE-LINKED`); in 7.0 extension values (`_X`) are always permitted |
| `KV3007` | error | 7.0 and 5.5.5: a structure with neither a value nor substructures (`TRLR` excepted; a missing required substructure is `KV2003`). 5.5.5: "every GEDCOM record without subrecords must have a line value" |
| `KV3008` | warning | A date whose day is beyond the length of its month: `31 NOV`, `29 FEB 1900`, `FRENCH_R 7 COMP`. February follows the leap years of its calendar; Hebrew months use their longest length |
| `KV3009` | warning | 5.x: a dual year whose second year does not follow the first, such as `1701/99` (`1701/02` is meant) |
| `KV3010` | error | 5.5.5: a text value shorter or longer than its `{Size=min:max}`, counted in characters of the value with its `CONC`/`CONT` lines. A value the specification lists for the element is valid whatever its length (`NAME.TYPE aka`, `FONE.TYPE kana`: `{Size=5:30}`) |

## KV4 — references

Reported by `Gedcom::validate()` in every version; `KV4003` needs the version's profile. Xrefs are
case-sensitive (`@test@` is not `@TEST@`). In 7.0 `@VOID@` is a null pointer and never dangles.

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV4001` | error (warning in an extension) | Pointer to a record that does not exist. In an extension structure or inside one it is a warning: what an extension points to is not defined (`_FAMC @VOID@`, a 7.0 null pointer kept in 5.5.1) |
| `KV4002` | error | Xref already used by an earlier record; pointers resolve to the first one |
| `KV4003` | error | Pointer to a record of the wrong type, e.g. `FAMC` to an `INDI` |
| `KV4004` | warning | A family link is not mutual: `INDI.FAMC` without `FAM.CHIL`, `INDI.FAMS` without `FAM.HUSB`/`WIFE`, or the reverse; only checked when the target exists |

## KV5 — plausibility

Optional: reported only with `ValidationOptions::withPlausibility()`, always as warnings, for
data that is valid but cannot be true. Dates are compared only when the conflict is certain:
a date stands for every day it can mean (`1907` is the whole year, `BEF 1907` has no first
day, `BET 1900 AND 1910` spans both years). Gregorian and Julian dates of every version are
compared, including `B.C.`/`BCE` and dual years (`1648/49` is 1649); approximate dates (`ABT`,
`CAL`, `EST`), date phrases and other calendars are not. The first `BIRT` and `DEAT` with
such a date count. Parents are `HUSB` and `WIFE` of the families an individual is a child of,
by `FAM.CHIL` or `INDI.FAMC`.

```php
$report = Gedcom::validate($doc, (new ValidationOptions())->withPlausibility());
```

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV5001` | warning | A birth after the death, reported at the birth date |
| `KV5002` | warning | A child born before a parent, reported at `FAM.CHIL` |
| `KV5003` | warning | An individual who is their own ancestor, reported once per cycle, at its first individual |

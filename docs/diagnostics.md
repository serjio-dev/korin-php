# Diagnostics reference

Every diagnostic has a stable code. A code never changes meaning within a major version;
new codes may be added in minor releases. Messages are informative and may change.

| Range | Level |
| --- | --- |
| `KV1xxx` | Syntax: line format, levels, encoding, HEAD/TRLR |
| `KV2xxx` | Structure: allowed substructures and cardinality |
| `KV3xxx` | Values: payload presence and data types (dates later in 0.2) |
| `KV4xxx` | References: dangling and duplicate xrefs, record types, family links |
| `KV5xxx` | Plausibility, optional *(0.2)* |
| `KL0001`–`KL9999` | Loss report entries of conversions *(0.3)* |

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
| `KV1101` | warning | `HEAD.CHAR` is not a standard value (e.g. `ANSI`, `LATIN1`); the encoding is detected from content |
| `KV1102` | warning | `HEAD.CHAR` contradicts the content; the content wins |
| `KV1103` | info | No usable `HEAD.CHAR`; the encoding is guessed from content |

In strict mode (`ReadOptions::withStrict()`) every error-level `KV1` diagnostic throws
`ParseException` instead.

## KV2 — structure

Reported by `Gedcom::validate()` against the profile of the document's version. Extension tags
(`_X`) and everything under them are not checked. `reference` names the profile term of the
structure that contains the problem, e.g. `https://gedcom.io/terms/v7/record-INDI`.

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV2000` | info | The version has no profile yet (5.x until 0.2 is complete); structures are not checked |
| `KV2001` | error | A standard tag that is not allowed where it stands (in this superstructure, or as a record); its substructures are not checked |
| `KV2002` | error | A substructure occurs more often than allowed; reported once, at the first extra occurrence |
| `KV2003` | error | A required substructure is missing; reported at the structure that lacks it |

Every code can be left out or reported with another severity:

```php
$options = (new ValidationOptions())->withDisabled('KV2001')->withSeverity('KV1101', Severity::Error);
$report = Gedcom::validate($doc, $options);
```

## KV3 — values

Reported by `Gedcom::validate()` for GEDCOM 7.0 against the payload type of each standard
structure (specification chapter 2); extension structures are not checked. Dates are not
checked yet. Messages name the expected type and never quote a value, except an enumeration
value shortened to 20 characters.

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV3001` | error | A value is required but missing (`Age`, `DateValue`, `DatePeriod` and `Y\|<NULL>` may be omitted) |
| `KV3002` | error | The structure must not have a value |
| `KV3003` | error | The value must be a pointer to a record |
| `KV3004` | error | A pointer where a value is expected (in 7.0 a leading `@` of text is written `@@`) |
| `KV3005` | error | The value does not match its data type: integer, personal name, BCP 47 language, media type, age, time, latitude, longitude, file path, URI, tag definition, `Y` |
| `KV3006` | error | An enumeration value that the structure does not permit; extension values (`_X`) are always permitted |
| `KV3007` | error | A structure with neither a value nor substructures (`TRLR` excepted; a missing required substructure is `KV2003`) |

## KV4 — references

Reported by `Gedcom::validate()` in every version; `KV4003` needs the version's profile. Xrefs are
case-sensitive (`@test@` is not `@TEST@`). In 7.0 `@VOID@` is a null pointer and never dangles.

| Code | Severity | Meaning |
| --- | --- | --- |
| `KV4001` | error | Pointer to a record that does not exist |
| `KV4002` | error | Xref already used by an earlier record; pointers resolve to the first one |
| `KV4003` | error | Pointer to a record of the wrong type, e.g. `FAMC` to an `INDI` |
| `KV4004` | warning | A family link is not mutual: `INDI.FAMC` without `FAM.CHIL`, `INDI.FAMS` without `FAM.HUSB`/`WIFE`, or the reverse; only checked when the target exists |

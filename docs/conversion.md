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

Dates, ages, `LANG`, media, `ASSO`/`RELA`, `SUBN`, inline `OBJE`/`SOUR`, identifiers and
`HEAD.SCHMA` follow in 0.3.

Not everything the gedcom7code converter does is done here: it strips `@#…@` escapes from
text (`obsolete @#OLD FORM@ escape` → `obsolete escape`), which drops data, and renames valid
xrefs (`@1@` → `@S1@`). Where the two agree, its 7.0 file is the corpus golden file.

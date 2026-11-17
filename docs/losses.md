# Loss report reference

`Gedcom::convert()` returns the converted document and a `LossReport`: everything the target
version cannot hold as it was. Upgrading is lossless; downgrading drops nothing by default
(`LossPolicy::PreserveAsExtension`), so data the target has no structure for becomes an
extension (`_` tag) and an entry here.

```php
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\ConvertOptions;

$result = Gedcom::convert($doc, Version::V5_5_1);
file_put_contents('family-551.ged', $result->output());
foreach ($result->losses() as $loss) {
    echo $loss, PHP_EOL;   // KL1001 warning line 7 (@I1@/NAME), dropped: A line that is not a GEDCOM line is dropped
}
echo json_encode($result->losses());   // {"from": "7.0", "to": "5.5.1", "entries": [...]}

Gedcom::convert($doc, Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::Fail));   // throws at the first loss
```

Every entry has a stable code, a severity, the path and line of the source document (path
format as in the corpus: `@I1@/BIRT/DATE`, an index only when a tag repeats), how the data
was kept (`storedAs`: `extension`, `note`, `moved` to another standard structure, or `dropped`) and a message that never quotes more
than a short fragment. A code never changes meaning within a major version.

| Policy | What happens to data the target cannot hold |
| --- | --- |
| `PreserveAsExtension` (default) | Kept as an extension structure, reported |
| `DropWithReport` | Dropped, reported |
| `Fail` | `ConversionException` at the first loss; `$e->loss` is the entry |

Lines that are not GEDCOM lines are not data: they are dropped under every policy (`KL1001`).

## Output conventions

`output()` is the converted file, byte for byte the same for the same input. `document()`
reads it back, on first use only: an ordinary document whose preserve-mode output is
`output()`; editing it does not change `output()`. What each version pair changes is in [conversion.md](conversion.md).

- `HEAD.GEDC.VERS` names the target version; in 5.x `HEAD.CHAR` names the output encoding.
- Encoding: UTF-8 for 7.0, 5.5.5 and 5.5.1; UTF-16 big-endian (`CHAR UNICODE`) for 5.5.
- BOM by the target version (SPEC section 3): 7.0, 5.5.5 and UTF-16 have one.
- Every line ends with the source's dominant line terminator, the last one too.
- `HEAD.SOUR` and `HEAD.DATE` are kept; Korin adds itself to the header only as the `HEAD.SOUR`
  5.x requires when the input has none (`1 SOUR Korin`). A missing `GEDC.FORM` of 5.x is
  `LINEAGE-LINKED`.
- Converting to the version a document already has converts nothing: the result is a copy,
  written as the input was, with an empty report.

The converter holds the source, its working copy (only changed lines take memory) and the
output at once: converting a 50 MB 5.5.1 file to 7.0 peaks at about 1.5 times the memory of
reading it. `Gedcom::convertFile($in, $out, $to)` converts a file record by record with the same
output and report, holding an index of the input instead of its tree (43 MB for 50 MB).

| Range | Level |
| --- | --- |
| `KL1xxx` | Syntax: lines, encoding |
| `KL2xxx` | Structures the target version does not have |
| `KL3xxx` | Values the target version cannot express |
| `KL4xxx` | References |

## KL1 — syntax

| Code | Severity | Meaning |
| --- | --- | --- |
| `KL1001` | warning | A line that is not a GEDCOM line (`KV1001`) is dropped; reported at the structure it is in. Empty lines are dropped without an entry |
| `KL1002` | warning | A line with bytes invalid in the source encoding (`KV1003`): they were read as U+FFFD, and the output keeps U+FFFD |

## KL2 — structures

| Code | Severity | Meaning |
| --- | --- | --- |
| `KL2001` | warning | A structure the target version does not have where it stands: kept as an extension with its tag and substructures (7.0 → 5.5.1: `NO` → `_NO`, `HEAD.SCHMA` → `_SCHMA`, a `PHRASE` → `_PHRASE`, an `EXID` with a `TYPE` 5.5.1 has no tag for → `_EXID`; 5.5.1 → 5.5: `EMAIL` → `_EMAIL`, the `FILE` of a media record → `_FILE`; 5.5 → 5.5.1: `BLOB` → `_BLOB`; 5.5.1 → 5.5.5: `BAPL` → `_BAPL`, `RESN` → `_RESN`), or dropped under `DropWithReport`. Converting back gives the structure back |
| `KL2002` | warning | More of a structure than the target version allows where it stands (5.5.1 → 5.5: a second `REPO` of a source; 5.5 → 5.5.1: a second `PEDI`; 5.5.1 → 5.5.5: a second `SUBM` record, which becomes a `_SUBM` record): the extra ones are kept as extensions, or dropped under `DropWithReport`. Converting back gives them back |

## KL3 — values

| Code | Severity | Meaning |
| --- | --- | --- |
| `KL3001` | warning | A value the target version cannot write so that converting back gives it again (7.0 → 5.5.1: a date of an extension calendar, an age in weeks, a language without a 5.5.1 name such as `en-GB`; between 5.5 and 5.5.1: an enumeration value the target does not list, such as 5.5 `SEX U`, or a missing pointer the target requires; 5.5.1 → 5.5.5: a structure with neither a value nor substructures such as `BURI`, an `ADDR` value whose lines `ADR1`–`ADR3` cannot hold, an age of `0y`; 5.5.5 → 5.5.1: a date without a year): the structure is kept as an extension with its tag (`_DATE`, `_AGE`, `_SEX`; the `ADDR` value in a first substructure `_ADDR`), or dropped under `DropWithReport` |
| `KL3002` | warning | 7.0 and 5.5.5 → 5.5.1: `SEX X` (and 5.5.5 `SEX N`), which 5.5.1 does not have, is `SEX U` with `_SEX X` (converting up gives `SEX X` back), or `SEX U` alone under `DropWithReport` |
| `KL3003` | warning | The value `Y` of an event the target writes without a value (5.5.1 `BURI`, `CREM`, `DIV`), on an event with substructures: dropped. An event without substructures means the same without it, and is not reported |
| `KL3004` | warning | 5.5.1 → 5.5: the value of `EVEN`, which 5.5 does not have, is its `TYPE` (`moved`), or a `NOTE` when it has a `TYPE` already (`note`) |

## KL4 — references

| Code | Severity | Meaning |
| --- | --- | --- |
| `KL4001` | warning | 7.0 → 5.5.1: a pointer to `@VOID@`, the 7.0 null pointer, which 5.5.1 does not have: the structure is kept as an extension with its tag (`FAMC @VOID@` → `_FAMC @VOID@`), or dropped under `DropWithReport` |

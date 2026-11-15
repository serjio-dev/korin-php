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
was kept (`storedAs`: `extension`, `note` or `dropped`) and a message that never quotes more
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
- `HEAD.SOUR` and `HEAD.DATE` are kept; Korin does not add itself to the header.
- Converting to the version a document already has converts nothing: the result is a copy,
  written as the input was, with an empty report.

The converter holds the source, its working copy (only changed lines take memory) and the
output at once: converting a 50 MB 5.5.1 file to 7.0 peaks at about 1.5 times the memory of
reading it. `convertFile()` will convert large files in constant memory.

| Range | Level |
| --- | --- |
| `KL1xxx` | Syntax: lines, encoding |
| `KL2xxx` | Structures the target version does not have *(0.3)* |
| `KL3xxx` | Values the target version cannot express *(0.3)* |
| `KL4xxx` | References *(0.3)* |

## KL1 — syntax

| Code | Severity | Meaning |
| --- | --- | --- |
| `KL1001` | warning | A line that is not a GEDCOM line (`KV1001`) is dropped; reported at the structure it is in. Empty lines are dropped without an entry |
| `KL1002` | warning | A line with bytes invalid in the source encoding (`KV1003`): they were read as U+FFFD, and the output keeps U+FFFD |

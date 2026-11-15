# Diagnostics reference

Every diagnostic has a stable code. A code never changes meaning within a major version;
new codes may be added in minor releases. Messages are informative and may change.

| Range | Level |
| --- | --- |
| `KV1xxx` | Syntax: line format, levels, encoding, HEAD/TRLR |
| `KV2xxx` | Structure: allowed substructures and cardinality *(0.2)* |
| `KV3xxx` | Values: dates, ages, enumerations, … *(0.2)* |
| `KV4xxx` | References: dangling and duplicate xrefs *(0.2)* |
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

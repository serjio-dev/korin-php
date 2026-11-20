# Upgrading

Until 1.0 a minor release may break the public API (`docs/SPEC.md`, section 12); every such
break is described here, with what to change. Changes of behaviour that keep the API, such as a
new diagnostic or a different conversion of a value, are in [CHANGELOG.md](CHANGELOG.md).

Diagnostic (`KV*`) and loss (`KL*`) codes are stable: a code keeps its meaning, and a new case
gets a new code.

## 0.8 → 1.0

From 1.0 the public API is everything not marked `@internal`, as listed in
`tests/Unit/public-api.txt`, and changes only in a major version. Marked `@internal` now, and
so free to change in any release:

- `Io\Codec\*`, `Io\EncodingDetector`, `Io\Detection`: read and write through `Gedcom`,
  `ReadOptions::withEncoding()` and `WriteOptions::withEncoding()`;
  `Document::encoding()` is the detected encoding.
- `Spec\Profile`, `Spec\Structure`, `Spec\Substructure`, `Spec\Cardinality`: the rules of a
  version are applied by `Gedcom::validate()`.
- `Syntax\Path`: pass a path as a string to `Document::find()`.
- `Document::createNode()`, `Document::hasPayload()`: edit through `Node` (`addChild()`,
  `setValue()`) and `Document::addRecord()`; `Node::value()`.
- `Encoding::ascii()`, `Encoding::codeUnitSize()`, `Version::escapesOnlyLeadingAt()`,
  `Writer::bomByDefault()` (`WriteOptions::withBom()` sets whether a file has a BOM).
- The `$steps` parameter of `Converter` and `$detector` of `Parser`: construct them without
  arguments.

## 0.7 → 0.8

No breaking changes. Worth knowing: `GedcomDate::days()` of a range that names its end first
(`BET 1950 AND 92 BCE`) spans the same days as the range in order instead of ending before it
begins, and plausibility no longer reports a conflict for such a date that is not certain.

## 0.6 → 0.7

No breaking changes. `Structure` gained an optional constructor parameter (`forms`). Worth
knowing:

- The 5.x validator checks a source citation and a media link by their form: `SOUR @S1@` +
  `TEXT`, an inline `SOUR` + `PAGE`, `OBJE @O1@` + `FILE` are `KV2001` now, an inline `OBJE`
  without `FILE` `KV2003`; 7.0 `HEAD` with both `NOTE` and `SNOTE` is `KV2002`. Files that
  validated before can have these errors.
- Conversions write as extensions some structures they wrote invalidly before (`KL2001`,
  `KL3001`: 5.x → 7.0 `_BAPL` and other empty structures, `_NOTE` and `_SOUR` under `TRAN`,
  `_FILE` of a `mailto:` URL; 7.0 → 5.x `_SNOTE` in `HEAD`), and a 5.0–5.4 draft converted to
  5.5 is written as 5.5 instead of copied. Golden files of conversions may need an update.

## 0.5 → 0.6

No breaking changes. Worth knowing:

- Validation of 7.0 gives `KV2005` warnings for deprecated structures and values (`ADR1`–`ADR3`,
  `HEAD.SOUR.DATA`, `EXID` without `TYPE`, six `STAT` values). Code that fails on any
  diagnostic, not only on errors, sees new ones on valid files.
- 7.0 → 5.x writes some values it wrote invalidly before as extensions (`_TIME`, `_AGE`,
  `_MEDI`, `_STAT`, `_SLGC`) and the value of `RESI` as a `NOTE` (new loss code `KL3005`).
  Golden files of conversions may need an update.

## 0.4 → 0.5

No breaking changes. `Version` gained the case `V4_0`: a `match` over every case of `Version`
needs an arm for it. `EncodingDetector::detect()` gained an optional parameter.

## 0.3 → 0.4

No breaking changes. `Document::walk()` and `Writer::serialize()` gained optional parameters.
`convert()` converts records in the order of the output, so the order of loss entries and new
xrefs can differ for records a rule moves (the 5.5.5 submitter after `HEAD`).

## 0.2 → 0.3, 0.1 → 0.2

No breaking changes.

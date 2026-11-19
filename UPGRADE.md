# Upgrading

Until 1.0 a minor release may break the public API (`docs/SPEC.md`, section 12); every such
break is described here, with what to change. Changes of behaviour that keep the API, such as a
new diagnostic or a different conversion of a value, are in [CHANGELOG.md](CHANGELOG.md).

Diagnostic (`KV*`) and loss (`KL*`) codes are stable: a code keeps its meaning, and a new case
gets a new code.

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

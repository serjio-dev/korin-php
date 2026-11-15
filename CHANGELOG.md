# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until 1.0 a minor release may
break the API; such changes are described in `UPGRADE.md`.

## [Unreleased]

### Added

- `Spec\Profile`: the rules of a GEDCOM version as data — top-level records, substructures
  with cardinality per superstructure, payload types, enumeration sets and calendars.
  The 7.0 profile is compiled from the FamilySearch GEDCOM v7.0.18 term files, the 5.5, 5.5.1
  and 5.5.5 profiles from transcriptions of the grammar in their specifications (`NOTICE`).
- `Gedcom::validate()` / `Validation\Validator` with `ValidationReport` (errors, warnings,
  `isValid()`, JSON) and `ValidationOptions` to disable codes or change their severity.
  Level 2 checks for every version: tags allowed in place (`KV2001`), cardinality (`KV2002`,
  `KV2003`).
- Level 3 checks for 7.0 (`KV3001`–`KV3008`): required and forbidden values, pointers, and the
  data types integer, enumeration and list of enumerations, personal name, language, media
  type, age, time, latitude, longitude, file path, URI and tag definition; dates
  (`DateValue`, `DatePeriod`, `DateExact`) with the months and epochs of the `GREGORIAN`,
  `JULIAN`, `FRENCH_R`, `HEBREW` and extension calendars. A day beyond the length of its month
  is a warning (`KV3008`).
- Level 3 checks for 5.5 and 5.5.1: the restricted primitive elements of the specification
  (enumerations such as `SEX_VALUE`, ages, times, coordinates, numbers, personal names) and dates
  with calendar escapes, date phrases, `B.C.` after any year and dual years; a dual year whose second year
  does not follow the first is a warning (`KV3009`).
- Level 3 checks for 5.5.5 with its stricter grammars (dates, ages, times, version numbers,
  place names), the lengths of text values (`KV3010`) and structures without a value or
  substructures (`KV3007`).
- Level 4 checks for every version: dangling pointers (`KV4001`), duplicate xrefs (`KV4002`),
  mutual family links (`KV4004`), and the record type a pointer targets
  (`KV4003`).
- `Diagnostic::$reference` (the specification term a rule comes from), `withSeverity()`, JSON.

## [0.1.0] - 2026-11-15

First release: the lossless foundation. Validation, conversion, the typed model, GEDZIP,
streaming and indexed reading follow in 0.2–0.4.

### Added

- `Gedcom::read()` / `Gedcom::parse()` for GEDCOM 5.5, 5.5.1, 5.5.5 and 7.0, with the version
  taken from `HEAD.GEDC.VERS` (or `ReadOptions::withVersion()`).
- Encodings: UTF-8 (with and without BOM), UTF-16 LE/BE, ASCII, ANSEL with combining
  diacritics, Windows-1250/1251/1252, CP437, CP850 and MacRoman. Detection order: explicit
  encoding, BOM, UTF-16 pattern, `HEAD.CHAR` checked against the content; non-standard values
  such as `CHAR ANSI` are resolved from the content. No extension is required; `ext-intl` and
  `ext-mbstring` are used when present.
- Lenient reading with diagnostics `KV1001`–`KV1008` and `KV1101`–`KV1103`
  ([docs/diagnostics.md](docs/diagnostics.md)); strict mode (`ReadOptions::withStrict()`)
  throws `ParseException` on errors. Limits on nesting depth and line length.
- `Syntax\Document`, a mutable tree with stable node ids and live `Node` views: `find()` by path
  (`@I1@/NAME[0]/SURN`), `records()`, `record()`, `head()`, `addRecord()`, `removeRecord()`,
  `renameXref()`, `copy()`; on nodes `value()` (CONC/CONT joined, `@@` unescaped per version),
  `pointer()`, `children()`, `all()`, `first()`, `path()`, `setValue()`, `setPointer()`,
  `addChild()`, `remove()`. Access to a removed node throws `DetachedNodeException`.
- `Document::create()` for a new document with the mandatory header.
- `Gedcom::write()` / `Gedcom::serialize()` in preserve mode: unchanged lines are written byte
  for byte, including BOM, line endings, spacing and a missing final newline; changed and new
  lines use the document's line ending (or `WriteOptions::withLineEnding()`).
- In 5.x, changed values longer than the line limit are split into `CONC` lines
  (`WriteOptions::withMaxLineLength()`, 255 by default).
- Compact storage: a full read of a 50 MB file takes about 1.6 s and 426 MB.
- Tested against [gedcom-corpus](https://github.com/serjio-dev/gedcom-corpus), including the
  gedcom7code test files; PHPBench benchmarks; a baseline comparison with
  liberu-genealogy/php-gedcom ([docs/comparison](docs/comparison/liberu-php-gedcom.md)).

[Unreleased]: https://github.com/serjio-dev/korin-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.1.0

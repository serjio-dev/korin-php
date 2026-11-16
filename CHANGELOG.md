# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until 1.0 a minor release may
break the API; such changes are described in `UPGRADE.md`.

## [Unreleased]

### Added

- `Gedcom::convert()` / `Conversion\Converter` with `ConversionResult` and `LossReport`
  ([docs/losses.md](docs/losses.md)), `ConvertOptions` with the loss policies
  `PreserveAsExtension`, `DropWithReport` and `Fail` (`ConversionException`). The framework
  and the 5.5.1 → 7.0 step below; the other steps follow. Lines that are not GEDCOM are dropped and reported (`KL1001`), lines with
  bytes invalid in their encoding are reported (`KL1002`). `ConversionResult::output()` is
  the converted file; `document()` reads it back on first use.
- Conversion 5.5.1 → 7.0, first rules ([docs/conversion.md](docs/conversion.md)): UTF-8 with
  a BOM, `CHAR` and `GEDC.FORM` removed, `CONC` joined, `@` escaped by the 7.0 rules, xrefs
  invalid in 7.0 renamed to `@X<n>@`, dangling pointers to `@VOID@`, `NOTE` records and
  pointers to them to `SNOTE`; `SUBN` to `HEAD._SUBN`, inline `OBJE` and `SOUR` to records,
  `AFN`/`RFN`/`RIN` to `EXID` with the 7.0 `TYPE` URIs, `_UID` to `UID`; dates (calendar
  names, `BCE`, dual years and date phrases to `PHRASE`), ages, `LANG` to BCP 47, media `FORM`
  to media types, `FILE` to URLs, `FORM.TYPE` to `MEDI`, `ASSO.RELA` to `ROLE` and
  enumeration values in 7.0 form (`OTHER` + `PHRASE` for values 7.0 does not have);
  `HEAD.FILE` removed, `FONE`/`ROMN` to `TRAN` with a BCP 47 `LANG`, and `HEAD.SCHMA` for
  the extension tags the conversion writes.
- Conversion 7.0 → 5.5.1, first rules: `GEDC.FORM` and `CHAR UTF-8` added, `@` escaped by
  the 5.5.1 rules and long lines split into `CONC`, `SNOTE` to `NOTE`, `HEAD._SUBN` back to a
  `SUBN` record, `UID` to `_UID`, `EXID` back to `AFN`/`RFN`/`RIN` (others to `_EXID`,
  `KL2001`), pointers to `@VOID@` to extensions (`KL4001`); dates (escapes, `B.C.`, a
  `PHRASE` back into the date), ages, `HEAD.LANG`/`SUBM.LANG` names, media types to file
  extensions, URLs to paths, `MEDI` to `FORM.TYPE`, `ROLE` to `RELA` and enumeration values in
  5.5.1 form, each chosen so that converting up gives the 7.0 value back; values 5.5.1 cannot
  write so are kept as extensions (`KL3001`).
- `WriteOptions::normalize()` writes every line anew by the version's rules with one line
  terminator; `withEncoding()` writes in another encoding (unchanged lines are re-encoded in
  preserve mode), `withBom()` sets the byte order mark, by default by the version (SPEC
  section 3) when the file is written anew.

### Fixed

- 7.0 validation: an empty `Text` or `List:Text` payload is valid (section 1 of the
  specification: `1 EVEN` is an `EVEN` with an empty payload), so `1 RESI` with a `PLAC` no
  longer gets `KV3001`. A structure with neither a payload nor substructures is still `KV3007`.
- `KV4001` for a pointer of an extension structure, or inside one, is a warning: what an
  extension points to is not defined.

## [0.2.0] - 2026-11-15

Profiles and validation: the rules of GEDCOM 5.5, 5.5.1, 5.5.5 and 7.0 as data, and a
validator with stable diagnostic codes ([docs/diagnostics.md](docs/diagnostics.md)). No
breaking changes. Conversion and the `LossReport` follow in 0.3.

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
  substructures (`KV3007`); a 5.5.5 file must start with a BOM (`KV1104`, a warning) and
  `SUBM` directly follow `HEAD` (`KV2004`).
- Level 4 checks for every version: dangling pointers (`KV4001`), duplicate xrefs (`KV4002`),
  mutual family links (`KV4004`), and the record type a pointer targets
  (`KV4003`).
- `Diagnostic::$reference` (the specification term a rule comes from), `withSeverity()`, JSON.
- Optional plausibility checks, `ValidationOptions::withPlausibility()` (warnings): a birth after
  the death (`KV5001`), a child born before a parent (`KV5002`), an individual who is their own
  ancestor (`KV5003`). Dates are compared as ranges of days, only when the conflict is certain.
- Specification conformance tests generated from every profile (`tests/Conformance`): each
  reachable structure in a minimal valid file, then a tag it does not allow, each substructure
  once too often or left out, and its value of the wrong type, missing, forbidden, or a
  pointer where text belongs and the reverse.
- Validation of a 50 MB file takes about 4.5 s and 405 MB (5.2 s and 466 MB with plausibility).

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

[Unreleased]: https://github.com/serjio-dev/korin-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.1.0

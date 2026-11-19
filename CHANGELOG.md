# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until 1.0 a minor release may
break the API; such changes are described in `UPGRADE.md`.

## [Unreleased]

### Added

- Line coverage in CI (pcov, `Coverage` job, report as an artifact and to Codecov). The 1.0
  criterion is line coverage ≥ 95% for `Io`, `Syntax`, `Conversion` and `Writer` with MSI ≥ 85%;
  branch coverage is no longer one: Xdebug's path coverage crashes on this code base.
- Mutation testing (Infection, `mutation` workflow): MSI ≥ 85% on the lines a pull request
  changes and on the whole code weekly; 82.2% → 85%+ with new unit tests, among them the
  file checks of the parser, which only the corpus suite had covered.
- Generative tests (`tests/Generative`, SPEC section 10, kind 6): random valid documents of
  7.0, 5.5.5, 5.5.1 and 5.5 from the grammars of their profiles go through the conversion
  matrix and 7.0 up and down again; 10 seeds per version in every run, 300 new ones a night.
  The matrix and up-down-up checks of the corpus are shared with them, and up-down-up now
  compares substructures of different tags in any order. Bugs they found are listed in the
  test until fixed.
- `Utf16Codec` takes `useMbstring: false` to force the pure-PHP encoder, like
  `AnselCodec(useIntl: false)`, so that tests cover the path without the extension.

### Fixed

- 7.0 → 5.x keeps `HEAD.SNOTE` as `_SNOTE` (`KL2001`) instead of writing `HEAD.NOTE @N1@`,
  which 5.x does not allow: its `HEAD.NOTE` is text. Converting up makes it `SNOTE` again.

## [0.6.0] - 2026-11-19

The official GEDCOM 7 test files: the 25 files of gedcom.io are in the corpus (downloaded by
its script, as they come without a license), read and validated as expected, and converted to
every 5.x version and back without errors or unreported changes. CI follows specification
section 11 (PHP 8.6 required, nightly PHP, dependency checks, release workflow), and the root
files of section 13 are in place. No breaking changes ([UPGRADE.md](UPGRADE.md)); valid 7.0
files can now have `KV2005` warnings. Performance is that of 0.5.

### Added

- `KV2005` (warning): 7.0 structures and values the specification deprecates (`ADR1`–`ADR3`,
  `HEAD.SOUR.DATA`, `EXID` without `TYPE`, six `STAT` values), from `spec/7.0/deprecations.yaml`,
  which `bin/generate-profiles.php` checks against the term files.
- Validation of 7.0 reads `HEAD.SCHMA`: an extension tag defined with the URI of a standard
  record type, calendar or month is checked as that (`_USER` for `record-SUBM` satisfies
  `SUBM @U1@`, a date in `_CALENDRIER` for `cal-FRENCH_R` is a French Republican date).
- The corpus runs the 25 GEDCOM 7.0 test files of gedcom.io as external cases; the suites
  leave them out, and say so, until `fetch-external.py` of the corpus has downloaded them.

### Changed

- CI per specification section 11: PHP 8.6 is a required version (Windows and macOS on 8.2 and
  8.6), a non-blocking `php-nightly` workflow, `composer-require-checker` and `composer-unused`
  in `static`, Dependabot for Composer and Actions, and a `release` workflow that publishes a
  tag's GitHub Release with its CHANGELOG notes and an attested package archive.
- The package no longer ships `mkdocs.yml` and `phpdoc.dist.xml`.
- Root files per specification section 13: `UPGRADE.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`
  (Contributor Covenant 2.1), `SECURITY.md`, `CITATION.cff`, issue and pull request templates.
- 7.0 dates in an extension calendar may use any months and epochs (they were an error unless
  extension tags, a rule the specification does not have).

### Fixed

- `maximal70.ged` and `extensions.ged` of gedcom.io are valid: `KV3005` and `KV4003` on their
  documented extension tags are gone.
- 7.0 → 5.x writes valid files for the gedcom.io test files: a time in UTC (`_TIME`), an `AGE`
  that is only a `PHRASE` (`_AGE`), a value a closed 5.5.1 list lacks (`_MEDI OTHER` with its
  `PHRASE`, `_STAT INFANT`), `SLGC` with `FAMC @VOID@` (`_SLGC`, `KL4001`), all `KL3001`; the
  value of `RESI` becomes a `NOTE` (new `KL3005`); a record `HEAD.SCHMA` defines as a standard
  one gets its tag (`_USER` → `SUBM`).
- 7.0 → 5.x → 7.0 gives the document back: a `RESN` list is kept by 5.5 when it lists every
  item and comes back from `_RESN`; `SNOTE` where 5.5.1 has no `NOTE` stays `_SNOTE`; a fourth
  `SUBM.LANG` keeps its tag; 5.x → 7.0 no longer encodes the `%` of a percent-encoding
  (`%C3%AB` became `%25C3%25AB`) and keeps a pointer of an extension to no record (`_IN @B1@`
  became `@VOID@`).
- `ext-ctype` is no longer needed (`ctype_digit` in the 5.5.5 date spelling was a hidden
  dependency).

## [0.5.0] - 2026-11-18

Old versions and tooling: GEDCOM 4.0 and the 5.0–5.4 drafts are read, documented mistakes of
vendor programs are corrected as a file is read, and the library has a command line
([korin/gedcom-cli](https://github.com/serjio-dev/korin-cli)), a documentation site and nightly
fuzzing, whose findings are fixed below. No breaking changes: `Version` gained the case `V4_0`
(a `match` over every case needs it), `EncodingDetector::detect()` an optional parameter.
Performance is that of 0.4.

### Added

- GEDCOM 4.0, read only (best effort, SPEC section 3): `Version::V4_0` for `HEAD.GEDC.VERS`
  `4.0`, `4.0.4.18` (PAF) and `4+` (Roots, Ultimate Family Tree), validated with the 5.5 profile
  (`Version::readAs()`). It converts to 5.5 and on: `SEX Male`/`Female` → `M`/`F`, dates in
  words in their 5.5 form (`11 June 1861` → `11 JUN 1861`, `Abt. 1850` → `ABT 1850`), irregular
  dates as date phrases, structures 5.5 lacks as extensions (`KL2001`)
  ([docs/conversion.md](docs/conversion.md#40--55)). Writing it normalized, or converting to it,
  throws `UnsupportedVersionException`; preserve mode writes it. `Version::isWritable()`.
- `KV1009` (info): a file of GEDCOM 4.0 or of a 5.0–5.4 draft, read with the 5.5 profile.
  `5.01`, which some programs write for the 5.1 draft, is read as 5.5 (it was an unknown version).
- Vendor dialects ([docs/dialects.md](docs/dialects.md)): `Document::sourceSystem()` and
  `IndexedDocument::sourceSystem()` give the program that wrote the file (`Extension\SourceSystem`,
  `Extension\Program` for the identifiers Tamura Jones lists). Documented mistakes are corrected
  as the file is read, reported as `KV1010` (info), off with `ReadOptions::withDialects(false)`:
  PAF 5 files declaring 5.5 are read as 5.5.1, `CHAR IBMPC` of Brother's Keeper before 6.1.12
  as Windows-1252, `CHAR ASCII` with `VERS MacOS Roman` (GEDitCOM) or `MACINTOSH`
  (MacFamilyTree) as MacRoman. `HEAD.CHAR` `MS-DOS`, `IBM DOS` (CP850), `OEM` (CP437),
  `WINDOWS`, `IBM WINDOWS` (Windows-1252) are read too.
- Documentation site, [serjio-dev.github.io/korin-php](https://serjio-dev.github.io/korin-php/):
  guides, reference and the API reference, built from `docs/` and deployed from `main`. The PHP
  examples of the README and the guides run as the `docs` test suite.
- Fuzzing with nikic/php-fuzzer: targets for reading, value grammars, codecs and GEDZIP in
  `fuzz/`, run nightly by the `fuzz` workflow ([docs/fuzzing.md](docs/fuzzing.md)).
- `ParseException` is documented on `Gedcom::read()`, `parse()`, `open()` and `stream()`: outside
  strict mode it is thrown only for the limits of `ReadOptions`.

### Fixed

- `HEAD.CHAR` of a file with Mac line endings (CR only) was not found, so its encoding was
  guessed from content.

Found by fuzzing ([docs/fuzzing.md](docs/fuzzing.md)):

- `Ơ`, `ơ`, `Ư`, `ư` could not be written in ANSEL (`UnencodableCharacterException` on writing an
  edited ANSEL file with a Vietnamese name): they are the ANSEL letters 0xAC, 0xBC, 0xAD, 0xBD.
- An ANSEL combining mark before a delimiter made `open()` and `stream()` find other records
  than `read()`.
- Diagnostics of one line are sorted by code, so `open()` and `read()` give the same report.
- Years longer than an int raised a PHP warning in validation and a `TypeError` in
  `GedcomDate::parse()`; a `Date` holds years up to `Date::MAX_YEAR` (999 999 999), and a longer
  one is an `InvalidValueException` (`tryParse()`: null). 4.0 dates are checked against the 5.5
  grammar rather than the model.
- A conversion raised a PHP warning on an empty line in some files.
- A damaged `gedcom.ged` in a GEDZIP archive raised a PHP warning, or with a wrong CRC no error;
  it is a `GedzipException`.

## [0.4.0] - 2026-11-17

Model and media: files of any size (streaming, indexed reading, streaming conversion and
validation), value objects for dates with calendars, ages, names and places, live views of
every record type, and GEDZIP archives. No breaking changes: the validator's rules, rewritten
as visitors, are internal; `Document::walk()` and `Writer::serialize()` only gained optional
parameters. 4.0 and 5.0–5.4 input, vendor dialects and the CLI follow in 0.5.

### Added

- GEDZIP: `Gedcom::readGedzip()` gives a `Gedzip\Archive` with the document, the media
  entries as streams (`media()`, `has()`; `FILE` references are URIs) and the references with
  no entry; `Gedcom::writeGedzip()` writes a 7.0 document and its media, through a temporary
  file. Unsafe entry names (zip-slip) and symbolic links are ignored (KV1201, KV1202), a
  document that is not 7.0 is reported (KV1203), so is a missing medium (KV1204); limits on
  total size and compression ratio (`ReadOptions::withArchiveLimits()`) reject zip bombs
  before decompression. Needs `ext-zip`. The `gedzip` corpus check.
- `open()`, and so `convertFile()`, read a `zip://family.gdz#gedcom.ged` path forward and
  open it again to go back, instead of failing: converting a 50 MB entry takes the memory of a
  plain file (41 MB) and nothing goes to disk; going back decompresses the entry again, so
  random access on a large entry is slow. Another path that cannot seek (`php://stdin`) is
  copied to `php://temp`, like a resource.
- `Gedcom::stream()` / `Syntax\RecordStream`: a file (a path or a stream resource) read one
  `Syntax\Record` at a time, in about 2.4 MB whatever its size (50 MB in 2.9 s). Each record
  is a document of its own with the nodes, line numbers and diagnostics `read()` gives it;
  written in preserve mode, the records are the file byte for byte (the `roundtrip-stream`
  corpus check). The encoding is detected on the first 64 KB; the stream's `diagnostics()`
  are those of the whole file (encoding, `HEAD`, `TRLR`, version). The version comes from the
  first `HEAD`.
- `Gedcom::open()` / `Syntax\IndexedDocument`: a file indexed without its tree (about 100
  bytes per record: 16 packed for offset, line and tag, the rest for the xref map; 27 MB and
  0.71 s for 50 MB), records parsed from the file by xref, path or in order (10 µs for an
  uncached one), the last 1000 cached. Records are read only (`ReadOnlyException`,
  `Document::isReadOnly()`), a `copy()` of one can be edited; `load()` reads the whole file
  into a `Document`. In strict mode every record is parsed while indexing, so `open()` rejects
  what `read()` rejects. A stream that cannot seek is copied to `php://temp`. Every corpus
  input gives the records, lines and diagnostics of `read()`.
- `Gedcom::convertFile($in, $out, $to)` / `Converter::convertFile()`: conversion of a file
  record by record, written as it goes, with the output and `LossReport` of `convert()` byte
  for byte (the `convert-stream` corpus check and every cell of the conversion matrix): 43 MB
  for 50 MB to 7.0 (`read()` and `convert()` peak at 674 MB), in 8.2 s (5.4 s for `read()`
  and `convert()`). HEAD takes along the records its rules move (SUBN into `HEAD._SUBN`, the
  5.5.5 submitter after HEAD); the records a conversion adds wait in `php://temp` for `TRLR`. A
  file whose first record is not HEAD is converted whole.
- `Gedcom::validate()` takes an `open()` document: the file is checked record by record, read
  once, with the report of the whole document (the `validate-indexed` corpus
  check): 64 MB and 10.4 s for 50 MB, plausibility checks included. The rules visit the
  records in order and keep what the checks between records need packed; the validation of a
  `Document` is as fast as before.

- Value objects in `Korin\Gedcom\Model`, the same for every version: `GedcomDate` (`DateKind`,
  `Date`: calendars, dual years, BCE, phrases; `days()` as Julian Day Numbers), `Age`
  (`AgeBound`, the 5.x words), `PersonalName`, `Place`, `Coordinates`. `parse($value, $version)`
  accepts what the validator finds valid for the version, without a warning; `toGedcom($version)`
  writes the version's form or throws `Exception\InvalidValueException`. Every date and age of
  the corpus that parses writes back to the same value object.

- Views of records in `Korin\Gedcom\Model`, live over the tree: `Individual` (names, sex,
  events, families as child and spouse, parents, spouses, children), `Family` (partners,
  children, events; `setHusband()`, `setWife()`, `addChild()` and `removeChild()` keep FAMS and
  FAMC mutual), `Event` (date, place, age, type; the 7.0 PHRASE and the 5.x `INT` read and
  written by the document's version), `Sex`. `Document::individuals()`, `individual()`,
  `families()`, `family()`, `addIndividual()` and `addFamily()` (xrefs @I1@, @F1@ … in order);
  the same on `IndexedDocument` (read only, records read from the file);
  `Record::view()` for a streamed record, which throws `RequiresDocumentException` for anything
  in another record.

- Views of the other records: `Source` (title, author, publication, repositories with their
  call numbers), `Repository`, `MediaObject` with its `MediaFile`s, `SharedNote` (SNOTE in 7.0,
  NOTE in 5.x) and `Submitter`; `sources()`, `source()`, `repositories()`, `mediaObjects()`,
  `sharedNotes()`, `submitters()` and the others on `Document` and `IndexedDocument`, and
  `Record::view()` for each.

### Changed

- `convert()` converts the records in the order of the output: a record a rule moves right
  after the one it converts (the 5.5.5 submitter after HEAD) is converted next, and the records
  a rule adds right after the record that added them. Outputs of the corpus are unchanged; the
  order of loss entries and new xrefs can differ for such records.

## [0.3.0] - 2026-11-17

Conversion: every pair of GEDCOM 5.5, 5.5.1, 5.5.5 and 7.0, directly or through 5.5.1, with a
`LossReport` of everything the target cannot hold ([docs/losses.md](docs/losses.md)), and the
conversion matrix over the corpus. No breaking changes. Streaming conversion (`convertFile()`)
follows in 0.4 with the indexed mode it is built on; 4.0 and 5.0–5.4 input in 0.5.

### Added

- `Gedcom::convert()` / `Conversion\Converter` with `ConversionResult` and `LossReport`
  ([docs/losses.md](docs/losses.md)), `ConvertOptions` with the loss policies
  `PreserveAsExtension`, `DropWithReport` and `Fail` (`ConversionException`). Lines that are
  not GEDCOM are dropped and reported (`KL1001`), lines with bytes invalid in their encoding
  are reported (`KL1002`). `ConversionResult::output()` is the converted file; `document()`
  reads it back on first use.
- Conversion 5.5.1 → 7.0 ([docs/conversion.md](docs/conversion.md)): UTF-8 with
  a BOM, `CHAR` and `GEDC.FORM` removed, `CONC` joined, `@` escaped by the 7.0 rules, xrefs
  invalid in 7.0 renamed to `@X<n>@`, dangling pointers to `@VOID@`, `NOTE` records and
  pointers to them to `SNOTE`; `SUBN` to `HEAD._SUBN`, inline `OBJE` and `SOUR` to records,
  `AFN`/`RFN`/`RIN` to `EXID` with the 7.0 `TYPE` URIs, `_UID` to `UID`; dates (calendar
  names, `BCE`, dual years and date phrases to `PHRASE`), ages, `LANG` to BCP 47, media `FORM`
  to media types, `FILE` to URLs, `FORM.TYPE` to `MEDI`, `ASSO.RELA` to `ROLE` and
  enumeration values in 7.0 form (`OTHER` + `PHRASE` for values 7.0 does not have);
  `HEAD.FILE` removed, `FONE`/`ROMN` to `TRAN` with a BCP 47 `LANG`, and `HEAD.SCHMA` for
  the extension tags the conversion writes.
- Conversion 7.0 → 5.5.1: `GEDC.FORM` and `CHAR UTF-8` added, `@` escaped by
  the 5.5.1 rules and long lines split into `CONC`, `SNOTE` to `NOTE`, `HEAD._SUBN` back to a
  `SUBN` record, `UID` to `_UID`, `EXID` back to `AFN`/`RFN`/`RIN` (others to `_EXID`,
  `KL2001`), pointers to `@VOID@` to extensions (`KL4001`); dates (escapes, `B.C.`, a
  `PHRASE` back into the date), ages, `HEAD.LANG`/`SUBM.LANG` names, media types to file
  extensions, URLs to paths, `MEDI` to `FORM.TYPE`, `ROLE` to `RELA` and enumeration values in
  5.5.1 form, each chosen so that converting up gives the 7.0 value back; values 5.5.1 cannot
  write so are kept as extensions (`KL3001`); `TRAN` back to `FONE`/`ROMN`, `SEX X` to
  `SEX U` + `_SEX X` (`KL3002`), and any other structure 5.5.1 does not have where it stands
  (`NO`, `SDATE`, `CREA`, `HEAD.SCHMA`, a remaining `PHRASE`) to an extension with its tag
  (`KL2001`). 5.5.1 → 7.0 turns these extensions back into the 7.0 structures, so a 7.0 file
  converted to 5.5.1 and back has the same fingerprint, except for `KL3` losses (tested on
  every 7.0 input of the corpus).
- Conversion 5.5.1 ↔ 5.5, and so 7.0 ↔ 5.5 through 5.5.1: 5.5 is written in UTF-16 with
  `CHAR UNICODE`; structures 5.5 does not have (`EMAIL`, `FAX`, `WWW`, `MAP`, `FONE`, `ROMN`,
  the `FILE` of a media record) and those 5.5.1 does not have (`BLOB`, the `AGE` of a family
  event) become extensions (`KL2001`), more than the target allows too (`KL2002`), values
  the target does not list (`SEX U` in 5.5) likewise (`KL3001`); media `FORM` moves between
  the file and the object and is spelled by the version (`jpg` ↔ `jpeg`), `ASSO.TYPE INDI` is
  added and removed, the value of `EVEN` becomes its 5.5 `TYPE` or a `NOTE` (`KL3004`, the
  new `StoredAs::Moved`). Converting up turns such extensions back into the structures. A
  7.0 file converted to 5.5 and back has the same fingerprint, except for `KL3` losses.
- Conversion 5.5.1 ↔ 5.5.5, and so 7.0 and 5.5 ↔ 5.5.5 through 5.5.1: `GEDC.FORM` gets
  `VERS 5.5.5`, the `SUBM` record `HEAD.SUBM` points to moves after `HEAD` (others become
  `_SUBM` records, `KL2002`), `SUBN` moves to `HEAD._SUBN`, inline `SOUR` and `OBJE` become
  records, the value of `ADDR` goes to `ADR1`–`ADR3` (or `_ADDR`, `KL3001`) and comes back,
  structures with neither a value nor substructures (`BURI`, `BIRT Y`) become extensions
  (`KL3001`), dates, ages and times are respelled for the stricter 5.5.5 grammars
  (`05 JAN 50` → `5 JAN 050`, `<1y` → `< 1y`) and back, `SEX X`/`N` become `SEX U` + `_SEX`
  in 5.5.1 (`KL3002`). A 7.0 file converted to 5.5.5 and back has the same fingerprint,
  except for `KL3` losses.
- The conversion matrix (`tests/Corpus/MatrixTest.php`): every corpus input to every version,
  valid output but for the documented limits of the target, deterministic and stable. For it:
  conversions to 5.x add the `HEAD.SOUR` (`Korin`) and `GEDC.FORM` 5.x requires when the
  input has none; 5.5.1 `EVEN` without `TYPE` gets its value as `TYPE` in 7.0 (removed again
  on the way back), and `REPO` of a source without a pointer is `REPO @VOID@`.
- Benchmarks of 5.5.1 → 5.5 and → 5.5.5.
- Every conversion step writes the `Y` of an event the target has no value for as no value
  (`KL3003` when the event has substructures), and 5.5.1 → 7.0 writes an event with neither
  a value nor substructures as `Y`.
- `WriteOptions::normalize()` writes every line anew by the version's rules with one line
  terminator; `withEncoding()` writes in another encoding (unchanged lines are re-encoded in
  preserve mode), `withBom()` sets the byte order mark, by default by the version (SPEC
  section 3) when the file is written anew.

### Fixed

- 5.5.5 validation: a value the specification lists for `NAME_TYPE`, `PHONETISATION_METHOD`
  or `ROMANISATION_METHOD` (`aka`, `kana`) is valid, although it is shorter than the printed
  `{Size=5:30}` (`KV3010`).
- 7.0 validation: an empty `Text` or `List:Text` payload is valid (section 1 of the
  specification: `1 EVEN` is an `EVEN` with an empty payload), so `1 RESI` with a `PLAC` no
  longer gets `KV3001`. A structure with neither a payload nor substructures is still `KV3007`.
- `KV4001` for a pointer of an extension structure, or inside one, is a warning: what an
  extension points to is not defined.
- Reading UTF-16 took quadratic time in a file without CR (11 s for a 10 000-line file): the
  line splitter searched the rest of the file for a CR on every line.

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

[Unreleased]: https://github.com/serjio-dev/korin-php/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.6.0
[0.5.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.5.0
[0.4.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.4.0
[0.3.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.3.0
[0.2.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/serjio-dev/korin-php/releases/tag/v0.1.0

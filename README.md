# Korin — lossless GEDCOM for PHP

Read, validate, convert and write GEDCOM 5.5, 5.5.1, 5.5.5 and 7.0 —
without losing a single tag. Every unavoidable loss is reported.

**Documentation:** [serjio-dev.github.io/korin-php](https://serjio-dev.github.io/korin-php/) —
guides, reference and the [API](https://serjio-dev.github.io/korin-php/api/).

> **Status: 0.5 — early development.** Reading, lossless writing, editing, validation and
> conversion between 5.5, 5.5.1, 5.5.5 and 7.0 work today, also for files of any size (streaming
> and indexed reading, streaming conversion), and the model: views of every record type with
> dates, ages, names and places, and GEDZIP archives. GEDCOM 4.0 and the 5.0–5.4 drafts are read
> too, and documented mistakes of vendor programs corrected. 1.0 follows once the acceptance
> criteria of [the specification](docs/SPEC.md#14-milestones-and-acceptance-criteria) are met;
> see [CHANGELOG.md](CHANGELOG.md) for releases.
> Until 1.0 a minor release may break the API.

```php
use Korin\Gedcom\Gedcom;

$doc = Gedcom::read('family.ged');          // version and encoding detected automatically
echo $doc->version()->value;                // "5.5.1"
echo $doc->encoding()->value;               // "Windows-1251"

$name = $doc->find('@I1@/NAME');
echo $name?->value();                       // "Анна /Коваль/"
$name?->setValue('Анна /Ковальова/');

Gedcom::write($doc, 'family.ged');          // only the changed line is rewritten
```

## Why Korin

- **Lossless.** Unknown and vendor tags (`_UID`, `_FSFTID`, …) are kept. Writing an
  unmodified document reproduces the input byte for byte: BOM, line endings, spacing.
- **Encodings that real files use.** UTF-8, UTF-16 LE/BE, ANSEL with combining diacritics,
  ASCII, Windows-1250/1251/1252, CP437, CP850, MacRoman — including files that declare
  `CHAR ANSI` or contradict their own `CHAR`.
- **No runtime dependencies.** `ext-intl` and `ext-mbstring` are used when present.
- **Tested against a shared corpus** — [gedcom-corpus](https://github.com/serjio-dev/gedcom-corpus),
  including the [gedcom7code test files](https://github.com/gedcom7code/test-files).

## Requirements

PHP 8.2–8.6, 64-bit.

## Installation

```bash
composer require korin/gedcom
```

## Diagnostics

Reading reports syntax problems, validation checks the document against its version's rules.
Documented mistakes of the program that wrote a file (PAF 5 declaring 5.5 for 5.5.1, a wrong
`CHAR`) are corrected as it is read, and reported ([docs/dialects.md](docs/dialects.md)).
Problems are reported, not thrown, unless you ask for strict mode:

```php
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Validation\ValidationOptions;

foreach ($doc->diagnostics() as $d) {
    echo $d, PHP_EOL;   // KV1101 warning line 6 (HEAD/CHAR): Non-standard CHAR value; …
}

Gedcom::read('family.ged', (new ReadOptions())->withStrict());   // throws ParseException on errors

$report = Gedcom::validate($doc);   // structure, values and references of every version
$report->isValid();                  // false if there are errors
Gedcom::validate($doc, (new ValidationOptions())->withPlausibility());   // also births after deaths, cycles
echo json_encode($report);           // code, severity, message, line, path, reference
```

All codes are listed in [docs/diagnostics.md](docs/diagnostics.md).

## Conversion

Any of 5.5, 5.5.1, 5.5.5 and 7.0 converts to any other; GEDCOM 4.0 and the 5.0–5.4 drafts are
read (best effort, with the 5.5 profile) and convert to any of them. Nothing is dropped silently:
data the target version cannot hold is kept as an extension tag and reported with a stable code.

```php
use Korin\Gedcom\Version;

$result = Gedcom::convert($doc, Version::V5_5_1);
file_put_contents('family-551.ged', $result->output());
foreach ($result->losses() as $loss) {
    echo $loss, PHP_EOL;   // KL3002 warning line 19 (@I1@/SEX), extension: SEX X has no 5.5.1 value: SEX U
}
```

The rules for each version pair are in [docs/conversion.md](docs/conversion.md), the loss codes
and policies in [docs/losses.md](docs/losses.md).

## Model

`Individual`, `Family` and their `Event`s are live views of the tree: they read from it and
write to it, so nothing they do not know about is lost. Linking a family also sets the pointer
back (`FAMS`, `FAMC`):

```php
foreach ($doc->individuals() as $person) {
    echo $person->name()?->full(), ' — ', $person->birth()?->date()?->toGedcom(), PHP_EOL;
}
$doc->individual('@I3@')?->parents();          // HUSB and WIFE of its FAMC families

$doc = Document::create(Version::V7_0);
$anna = $doc->addIndividual()                  // @I1@
    ->setName(PersonalName::of('Анна', 'Коваль'))
    ->setSex(Sex::Female);
$anna->addBirth(GedcomDate::parse('12 MAR 1921'), Place::of('Полтава, Україна'));
$doc->addFamily()->setWife($anna)->addChild($doc->addIndividual());   // FAMS and FAMC set too
```

`Source`, `Repository`, `MediaObject`, `SharedNote` (SNOTE in 7.0, NOTE in 5.x) and `Submitter`
have views too: `$doc->source('@S1@')?->repositories()`, `$doc->mediaObjects()`.

The same views work on `open()` (read only, related records are read from the file) and, limited
to one record, on `stream()`: `$record->view()?->familyXrefs()` works, `parents()` throws
`RequiresDocumentException`.

Dates, ages, names, places and coordinates are immutable value objects in `Korin\Gedcom\Model`.
They are parsed by the grammar of a version and written for any version; a value a version
cannot write throws `InvalidValueException` rather than being changed:

```php
use Korin\Gedcom\Model\{Age, GedcomDate, PersonalName, Place};

$date = GedcomDate::parse('abt @#DJULIAN@ 1650', Version::V5_5_1);
$date->kind;                                // DateKind::About
$date->date?->calendar;                     // "JULIAN"
$date->toGedcom(Version::V7_0);             // "ABT JULIAN 1650"
GedcomDate::parse('BEF 1907')->days();      // [PHP_INT_MIN, 2417941]: Julian Day Numbers

Age::parse('<1y', Version::V5_5_1)->toGedcom(Version::V7_0);   // "< 1y"
PersonalName::parse('Анна /Коваль/')->withSurname('Ковальова');  // "Анна /Ковальова/"
Place::of('Полтава, Україна')->name();                           // "Полтава"
```

## GEDZIP

A `.gdz` archive (GEDCOM 7.0) holds `gedcom.ged` and the media its `FILE` references name.
Reading and writing it needs `ext-zip`.

```php
$archive = Gedcom::readGedzip('family.gdz');
$archive->document();                    // gedcom.ged
$archive->media('photos/anna%20k.jpg');  // a stream: FILE references are URIs, nothing goes to disk
$archive->missing();                     // relative references with no entry (KV1204)

$tree = Gedcom::convert($doc, Version::V7_0)->document();   // archives hold 7.0
Gedcom::writeGedzip($tree, 'family-7.gdz', ['photos/anna k.jpg' => 'photos/anna k.jpg']);   // entry => file
```

Archives are treated as hostile: entries with `..`, absolute paths, backslashes or drive
letters and symbolic links are ignored (KV1201, KV1202), and an archive is rejected before
anything is decompressed when its entries hold more than `ReadOptions::$maxArchiveSize` bytes
or one of more than 1 MiB is compressed more than `$maxCompressionRatio` times
(`withArchiveLimits()`). A media stream is the entry as the archive gives it: limit how much
you read when the archive is not yours.

Large archives need not be read whole: `gedcom.ged` can be streamed or converted through
PHP's `zip://` wrapper, decompressed as it is read and never copied to disk.

```php
Gedcom::convertFile('zip://family.gdz#gedcom.ged', 'family.ged', Version::V5_5_1);
Gedcom::stream('zip://family.gdz#gedcom.ged');
```

`Gedcom::open()` takes a `zip://` path too, but an entry cannot seek: going back to a record
decompresses the entry again from its start. `convertFile()` reads the file in order, in two
to five passes depending on the rules (50 MB 5.5.1 → 7.0: two passes, about 7% slower than a plain file, the same memory). For
random access (`individual()`, `validate()` with plausibility checks) on a large entry, extract
`gedcom.ged` first.

## Large files

`Gedcom::stream()` reads one record at a time; memory does not grow with the file. Each record
is a document of its own, with the lines and diagnostics `read()` would give it:

```php
$stream = Gedcom::stream('huge.ged');       // a path or an open stream resource
$out = fopen('copy.ged', 'wb');
foreach ($stream as $record) {
    if ($record->tag() === 'INDI') {
        echo $record->xref(), ' ', $record->node()->first('NAME')?->value(), PHP_EOL;
    }
    fwrite($out, Gedcom::serialize($record->document()));   // the record's original bytes
}
$stream->diagnostics();                     // encoding, HEAD, TRLR and version of the whole file
```

`Gedcom::open()` indexes the file (about 100 bytes per record) and reads a record from disk when
it is asked for; records are read only, `load()` reads the whole document:

```php
$doc = Gedcom::open('huge.ged');
echo $doc->find('@I1@/NAME')?->value(), PHP_EOL;
$family = $doc->record('@F1@');             // parsed from the file, the last 1000 records cached
$copy = $family?->document()->copy();       // an editable copy of the record
$report = Gedcom::validate($doc);           // record by record, the report of the whole document
```

`Gedcom::convertFile()` converts record by record, with the output and loss report of `convert()`:

```php
$losses = Gedcom::convertFile('huge.ged', 'huge-7.ged', Version::V7_0);
```

## CLI

[korin/gedcom-cli](https://github.com/serjio-dev/korin-cli) puts the library on the command
line: `korin info`, `validate`, `convert` and `diff` ([guide](docs/guide/cli.md)).

```bash
composer global require korin/gedcom-cli
korin validate family.ged --plausibility
```

## Performance

Measured with PHPBench on generated, fictional 5.5.1 files (PHP 8.5, Apple M-series, no opcache):

| Scenario | Peak memory | Time |
| --- | --- | --- |
| Full read, 10 MB UTF-8 | 70 MB | 0.35 s |
| Full read, 10 MB Windows-1251 | 105 MB | 0.52 s |
| Full read, 50 MB UTF-8 (2.4 million lines) | 426 MB | 1.8 s |
| Preserve-mode write of 10 MB, unchanged / 10% of surnames edited | 76 MB | 112 ms / 130 ms |
| Streaming read, 10 MB / 50 MB UTF-8 | 2.4 MB | 0.58 s / 2.9 s |
| `open()` index, 10 MB / 50 MB UTF-8; then an uncached record | 7.8 MB / 27 MB | 0.14 s / 0.71 s; 10 µs |
| `convert()` to 7.0 of a document read, 10 MB / 50 MB (memory with the document, time without the read) | 112 MB / 674 MB | 0.70 s / 3.6 s |
| `convertFile()` to 7.0, 10 MB / 50 MB | 14 MB / 43 MB | 1.7 s / 8.4 s |
| Validation of a document read, 10 MB, plausibility included (document included) | 76 MB | 1.3 s |
| `open()` and validation, 10 MB / 50 MB, plausibility included | 15 MB / 64 MB | 2.1 s / 10.6 s |

The 1.0 target for a full read of 50 MB is ≤ 512 MB and ≤ 20 s.
Run the benchmarks with `composer bench`.
A baseline comparison with liberu-genealogy/php-gedcom is in [docs/comparison](docs/comparison/liberu-php-gedcom.md).

## License and trademarks

Apache-2.0. GEDCOM is a trademark of Intellectual Reserve, Inc. Korin is not affiliated with
or endorsed by FamilySearch.

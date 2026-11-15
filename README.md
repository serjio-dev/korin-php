# Korin — lossless GEDCOM for PHP

Read, validate, convert and write GEDCOM 5.5, 5.5.1, 5.5.5 and 7.0 —
without losing a single tag. Every unavoidable loss is reported.

> **Status: early development (towards 0.1).** Reading, lossless writing and editing work today;
> validation, conversion, the typed model, GEDZIP and streaming are planned — see
> [the specification](docs/SPEC.md) for the roadmap.

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

PHP 8.2–8.6.

## Installation

```bash
composer require korin/gedcom
```

## Diagnostics

Problems are reported, not thrown, unless you ask for strict mode:

```php
use Korin\Gedcom\ReadOptions;

foreach ($doc->diagnostics() as $d) {
    echo $d, PHP_EOL;   // KV1101 warning line 6 (HEAD/CHAR): Non-standard CHAR value; …
}

Gedcom::read('family.ged', (new ReadOptions())->withStrict());   // throws ParseException on errors
```

All codes are listed in [docs/diagnostics.md](docs/diagnostics.md).

## Performance

Measured with PHPBench on generated, fictional 5.5.1 files (PHP 8.5, Apple M-series, no opcache):

| Scenario | Peak memory | Time |
| --- | --- | --- |
| Full read, 10 MB UTF-8 | 170 MB | 0.33 s |
| Full read, 10 MB Windows-1251 | 288 MB | 0.51 s |
| Full read, 50 MB UTF-8 (2.4 million lines) | 1.16 GB | 1.8 s |
| Preserve-mode write of 10 MB, unchanged / 10% of surnames edited | 175 MB | 80 ms / 100 ms |

The 1.0 target for a full read of 50 MB is ≤ 512 MB and ≤ 20 s; memory use is being reduced.
Streaming and indexed modes for large files are planned. Run the benchmarks with `composer bench`.

## License and trademarks

Apache-2.0. GEDCOM is a trademark of Intellectual Reserve, Inc. Korin is not affiliated with
or endorsed by FamilySearch.

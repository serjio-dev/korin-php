# Korin

Korin reads, validates, converts and writes GEDCOM 5.5, 5.5.1, 5.5.5 and 7.0 in PHP, without
losing a single tag. GEDCOM 4.0 and the 5.x drafts are read too. Every loss a conversion cannot
avoid is reported.

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

```bash
composer require korin/gedcom
```

PHP 8.2–8.6, 64-bit, no runtime dependencies. `ext-intl` and `ext-mbstring` are used when
present, `ext-zip` for GEDZIP archives.

## Guides

- [Reading files](guide/reading.md): versions, encodings, options, diagnostics of reading.
- [Editing and writing](guide/writing.md): the tree, preserve and normalize modes, encodings.
- [Validation](guide/validation.md): the checks, options and reports.
- [Converting between versions](guide/converting.md): the loss report and policies.
- [The model](guide/model.md): individuals, families, dates, ages, names and places.
- [Large files](guide/large-files.md): streaming, indexed reading, file conversion.
- [GEDZIP archives](guide/gedzip.md).
- [Command line](guide/cli.md): `korin info`, `validate`, `convert`, `diff`.

## Reference

- [Conversion rules](conversion.md) for each pair of versions.
- [Losses](losses.md): the `KL*` codes and policies.
- [Diagnostics](diagnostics.md): the `KV*` codes.
- [Vendor dialects](dialects.md): mistakes of programs Korin corrects.
- [API reference](https://serjio-dev.github.io/korin-php/api/), generated from the source.

## Project

- [Specification](SPEC.md): the design and the roadmap.
- [Fuzzing](fuzzing.md).
- [Comparison with liberu-genealogy/php-gedcom](comparison/liberu-php-gedcom.md).
- [Changelog](https://github.com/serjio-dev/korin-php/blob/main/CHANGELOG.md),
  [source](https://github.com/serjio-dev/korin-php),
  [test corpus](https://github.com/serjio-dev/gedcom-corpus).

GEDCOM is a trademark of Intellectual Reserve, Inc. Korin is not affiliated with or endorsed by
FamilySearch.

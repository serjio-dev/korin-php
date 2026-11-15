# Korin — working notes

Lossless GEDCOM library for PHP. The specification is `docs/SPEC.md`; read the
relevant section before changing behaviour. Diagnostic codes are listed in `docs/diagnostics.md`.

## Commands

```bash
composer check          # cs + rector + phpstan + phpunit
vendor/bin/phpunit      # unit + corpus suites
KORIN_CORPUS=../gedcom-corpus vendor/bin/phpunit --testsuite corpus   # against a local corpus checkout
php bin/generate-encoding-tables.php   # regenerate resources/encodings (needs iconv + intl)
```

## Rules

- PHP 8.2 syntax only: no typed class constants, `#[\Override]`, property hooks or other 8.3+ features.
  PHPStan runs with `phpVersion: 80200`; CI also runs on 8.2.
- Tests use PHPUnit attributes only (`#[Test]`, `#[DataProvider]`), never docblock annotations:
  they must run on PHPUnit 11, 12 and 13.
- No runtime dependencies. `ext-intl` / `ext-mbstring` may only speed things up; a pure-PHP path must exist.
- Never replace an unencodable character with `?`; throw `UnencodableCharacterException`
  (the loss report handles it in conversions).
- Exceptions and diagnostics must not quote more than a short fragment of the file: trees hold personal data.
- `Syntax\Document` is mutable; functions such as convert or validate never change their input.
  Node ids are stable: removal leaves a tombstone, nothing shifts.
- Preserve mode must reproduce unmodified lines byte for byte. Any change to Io/Syntax/Writer
  must keep every `roundtrip-preserve` corpus case green.

## Corpus

Behaviour that changes expected results goes to `gedcom-corpus` first (PR, merge), then here.
Checks implemented so far are listed in `tests/Corpus/CorpusTest.php` (`IMPLEMENTED`).

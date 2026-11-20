# Contributing

Thank you for helping. Korin is a lossless GEDCOM library: the specification of what it does
is [docs/SPEC.md](docs/SPEC.md); read the section of the part you change first.

## Family trees are personal data

Never attach a real family tree to an issue or a pull request, not even part of one. Make the
smallest fictional file that shows the problem (`0 HEAD` … `0 TRLR`, invented names and dates).
For a file you cannot share, describe its structure: the program that wrote it, the tags and
the lines around the problem with the names and places replaced.

## Setup

PHP 8.2 or newer, Composer, and for the full suites `ext-intl` and `ext-zip`.

```bash
composer install
python3 vendor/korin/gedcom-corpus/scripts/fetch-external.py   # inputs of the corpus's external cases
composer check   # php-cs-fixer, rector, phpstan, profiles, phpunit
```

Other commands (benchmarks, fuzzing, regenerating profiles and encoding tables) are in
[CLAUDE.md](CLAUDE.md) and [docs/fuzzing.md](docs/fuzzing.md).

## Rules

- **PHP 8.2 syntax.** No typed class constants, `#[\Override]`, property hooks or other 8.3+
  features; PHPStan runs at level `max` with `phpVersion: 80200`.
- **No runtime dependencies.** `ext-intl` and `ext-mbstring` may only make things faster; a
  pure-PHP path must exist.
- **Tests** use PHPUnit attributes (`#[Test]`, `#[DataProvider]`), never docblock annotations:
  they run on PHPUnit 11, 12 and 13.
- **Lossless.** Preserve mode reproduces unmodified lines byte for byte; a change to `Io`,
  `Syntax` or `Writer` keeps every `roundtrip-preserve` case of the corpus green. A conversion
  that cannot keep something reports it (`docs/losses.md`).
- **Version rules are data** in `spec/`, compiled by `bin/generate-profiles.php`; never edit
  `resources/profiles/*.php` by hand.
- **Messages** of exceptions and diagnostics quote at most a short fragment of a file.
- **Performance.** `Document` storage is packed (section 9 of the specification); run
  `composer bench` after a change to it. The `bench` workflow compares a pull request with its
  base branch and fails when a benchmark is more than 10% slower or needs more than 10% more
  memory.

## The corpus first

Behaviour that changes an expected result (a diagnostic, a conversion, a loss report) goes to
[gedcom-corpus](https://github.com/serjio-dev/gedcom-corpus) first, as a case with its
expectation; then the change here makes it pass.

## Pull requests

- One topic per pull request, with tests; `composer check` passes.
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`fix: …`, `feat: …`, `docs: …`); notable changes get a line in the `Unreleased` section of
  [CHANGELOG.md](CHANGELOG.md), and a break of the public API a section in [UPGRADE.md](UPGRADE.md).
- By contributing you agree that your work is licensed under the
  [Apache License 2.0](LICENSE), and to follow the [code of conduct](CODE_OF_CONDUCT.md).

Security issues are not reported in public issues: see [SECURITY.md](SECURITY.md).

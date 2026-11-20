# Fuzzing

[nikic/php-fuzzer](https://github.com/nikic/PHP-Fuzzer) runs four targets in `fuzz/` (SPEC
section 11). Each target fails on a crash, and also on an exception that is not documented or
on a broken rule of the library:

| Target | What it reads | Rules checked as well |
| --- | --- | --- |
| `read` | Any bytes, with small limits (depth 32, lines of 1 KB) | Preserve mode writes the input back byte for byte. `stream()` records add up to the input. `open()` + `validate()` gives the report of `read()` + `validate()`. Conversions to 5.5.1 and 7.0 read back in their version. |
| `values` | A version byte and a date, age, coordinates, name or place | A value that parses writes back to a value that parses to the same. A date's days do not end before they begin. |
| `decode` | An encoding byte and bytes | Decoded text is UTF-8 and encodes back to the same bytes (ANSEL: to the same text). |
| `gedzip` | Any bytes as a `.gdz` archive | Only `GedzipException`; damaged entries included. |

Outside strict mode, reading throws only when a limit of `ReadOptions` is exceeded
(`ParseException`); the targets allow exactly that.

```bash
php fuzz/seed.php ../gedcom-corpus      # build/fuzz/<target>: seeds from the corpus inputs
mkdir -p build/fuzz/crashes && cd build/fuzz/crashes
php -d memory_limit=2G ../../../vendor/bin/php-fuzzer fuzz ../../../fuzz/read.php ../read
../../../vendor/bin/php-fuzzer minimize-crash ../../../fuzz/read.php crash-<hash>.txt
```

The fuzzer keeps its corpus in memory and grows past the 128 MB of a default `php.ini` in a few
minutes: give it more. Crashes are written to the working directory as `crash-<hash>.txt`.

The `fuzz` workflow runs every target for 20 minutes each night, keeping the corpus between runs.
On demand it runs for as long as asked, in shards: several runs of every target side by side,
each for at most 330 minutes (a hosted job lasts 6 hours). The 1.0 criterion of 24 hours without
new crashes is 2 shards × 4 targets × 180 minutes:

```bash
gh workflow run fuzz.yml -f minutes=180 -f shards=2
```

A crash fails the run and is attached to it; so does a fuzzer that stops before its time. Fix it
with a unit test of the minimized input; an input that shows behaviour worth keeping goes to
gedcom-corpus as a case with `source.origin: fuzz`.

## Found so far

| Found | Fixed |
| --- | --- |
| `Ơ`, `ơ`, `Ư`, `ư` (Vietnamese) could not be written to an ANSEL file: NFD splits off a horn ANSEL has no mark for | Encoded as the ANSEL letters 0xAC, 0xBC, 0xAD, 0xBD |
| An ANSEL combining mark before a delimiter (`0\xE9 @1@ INDI`) made `open()` and `stream()` see other records than `read()` | Such lines are matched decoded, as `read()` does |
| Diagnostics of one line came in another order from `open()` than from `read()` | Sorted by line, code, path and message |
| Years longer than an int: a PHP warning in validation, a `TypeError` in `GedcomDate::parse()` | Validation takes a year modulo 10 000 (leap and dual years are the same); a `Date` holds years up to `Date::MAX_YEAR` |
| A conversion read the first byte of an empty tag (a PHP warning) | Tags are checked with `str_starts_with()` |
| A damaged `gedcom.ged` in a GEDZIP archive: a PHP warning, or with a wrong CRC no error at all | `GedzipException` |

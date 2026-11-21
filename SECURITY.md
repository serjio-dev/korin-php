# Security policy

## Supported versions

Until 1.0, security fixes go into the latest minor release only. After 1.0, the latest major
gets them, and the previous major for another 12 months (`docs/SPEC.md`, section 12).

| Version | Supported |
| --- | --- |
| 1.0 release candidates | ✅ until 1.0.0 |
| 0.8 | ✅ until 1.0.0 |
| Older minors | ❌ |

## Reporting a vulnerability

Please do not open a public issue. Report it privately through
[GitHub's vulnerability reporting](https://github.com/serjio-dev/korin-php/security/advisories/new)
("Report a vulnerability" on the Security tab), with the version, what happens, and a minimal
**fictional** file that triggers it. Never send a real family tree: it is personal data.

You get an answer within 7 days. A confirmed issue is fixed in a patch release, with a GitHub
security advisory and a CVE where it applies; you are credited unless you prefer not to be.

## What counts

Korin reads files from anywhere, so it treats every input as hostile. A vulnerability is, for
example:

- a file that gets past the limits of `ReadOptions` (nesting depth, line length, records, size),
  or makes reading, validation or conversion use far more memory or time than its size warrants
  (section 9 of the specification: 50 MB in at most 512 MB), or never finish; a regular
  expression with more than linear time (ReDoS);
- a GEDZIP archive that gets past the limits on compression ratio and total size (a zip bomb),
  or an entry path with `..`, an absolute path or a symlink that Korin follows (zip-slip);
- Korin opening or downloading a `FILE` URI, or any network access;
- an exception or a diagnostic that quotes more of a file than a short fragment.

An exception of the library on a malformed file (`Korin\Gedcom\Exception\GedcomException`)
is the documented behaviour, not a vulnerability; any other error or a PHP crash is a bug, and
found by fuzzing ([docs/fuzzing.md](docs/fuzzing.md)) it is fixed like one.

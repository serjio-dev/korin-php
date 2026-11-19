<!-- What changes and why; link the issue (Fixes #…). See CONTRIBUTING.md. -->

## Checklist

- [ ] `composer check` passes
- [ ] Tests for the change; fictional data only, no real family tree
- [ ] An expected result that changes is in [gedcom-corpus](https://github.com/serjio-dev/gedcom-corpus) first (PR: …)
- [ ] `CHANGELOG.md` (`Unreleased`); a break of the public API in `UPGRADE.md`
- [ ] Preserve mode still round-trips byte for byte (changes to `Io`, `Syntax`, `Writer`)

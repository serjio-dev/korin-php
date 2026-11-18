# Command line

[korin/gedcom-cli](https://github.com/serjio-dev/korin-cli) puts the library on the command
line: `korin info`, `validate`, `convert` and `diff`.

```bash
composer global require korin/gedcom-cli
# or in a project
composer require --dev korin/gedcom-cli && vendor/bin/korin list
```

```console
$ korin info family.ged
File         family.ged, 1.2 MB
Version      5.5
Encoding     Windows-1252 (CHAR IBMPC), CRLF
Program      Brother's Keeper 5.2
Records      4 812: INDI 3 120, FAM 1 040, SOUR 512, NOTE 140
Diagnostics  1 warning, 1 info (-v to list)

$ korin validate family.ged --plausibility
KV1101 warning line 8 (HEAD/CHAR): Non-standard CHAR value; encoding detected from content
KV1010 info line 8 (HEAD/CHAR): Brother's Keeper before 6.1.12 writes CHAR IBMPC for Windows-1252; read as Windows-1252
KV3006 error line 8 (HEAD/CHAR): CHAR does not permit "IBMPC"; expected one of ANSEL, UNICODE, ASCII
KV5001 warning line 912 (@I77@/BIRT/DATE): Birth is after the death on line 914
family.ged (GEDCOM 5.5): 1 error, 2 warnings, 1 info

$ korin convert family-7.ged family-551.ged --to 5.5.1
KL3002 warning line 19 (@I1@/SEX), extension: SEX X has no 5.5.1 value: SEX U
Converted to GEDCOM 5.5.1 in family-551.ged: 1 loss

$ korin diff family.ged family-edited.ged --ignore CHAN
--- family.ged  5.5.1, UTF-8
+++ family-edited.ged  5.5.1, Windows-1251
~ @I1@ INDI
    - @I1@/NAME Анна /Коваль/
    + @I1@/NAME Анна /Ковальова/
+ @I3@ INDI
1 changed, 1 added
```

| Command | Options |
| --- | --- |
| `info` | `-v`: the diagnostics of reading |
| `validate` | `--plausibility`, `--disable CODE` (repeatable), `--fail-on warning` |
| `convert` | `--to VERSION`, `--policy extension\|drop\|fail`, `--losses FILE.json`; `-` as output writes to the standard output |
| `diff` | `--ignore TAG` (repeatable): a tag with its substructures, or records of a tag |
| all | `--format json`, `--encoding NAME`, `--no-dialects` |

`diff` matches records by xref and compares them as trees: encodings, line endings, CONC/CONT
and the order of records do not count, so a file can be compared with its copy saved by another
program, or with its conversion.

Exit codes: `0` success, `1` what the command looks for was found (errors, differences, a
conversion stopped at a loss), `2` a file cannot be read or an option is wrong. A `.gdz` archive
is read through its `gedcom.ged`.

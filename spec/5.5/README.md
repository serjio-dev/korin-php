# GEDCOM 5.5 grammar

`grammar.yaml` is our transcription of the lineage-linked grammar in chapter 2 of
[The GEDCOM Standard, Release 5.5](https://gedcom.io/specifications/ged55.pdf)
(2 January 1996, pages 22–36), and `primitives.yaml` of its primitive elements (pages 37–56);
see `NOTICE`. The notation and the compilation are those of 5.5.1, described in
`spec/5.5.1/README.md`; structure URIs start with `https://gedcom.io/specifications/ged55.pdf#`.

5.5 is transcribed in full rather than as changes to 5.5.1, because 5.5.1 changed many of its
structures: the 5.5 `MULTIMEDIA_RECORD` holds the object itself (`BLOB`), `ASSO` names the
record type (`TYPE`), `ADDR` has a value continued with `CONT`, the individual and family
`EVEN` have no value, and there are no `EMAIL`, `FAX`, `WWW`, `MAP`, `FONE` or `ROMN`.

## What 5.5 adds to the notation

`FAM_RECORD` writes lines under an included structure:

```
+1 <<FAMILY_EVENT_STRUCTURE>>  {0:M}
  +2 HUSB                      {0:1}
    +3 AGE <AGE_AT_EVENT>      {1:1}
```

They belong to each top structure of the included one, so `FAM.MARR.HUSB` and `FAM.DIV.HUSB`
are both `…#FAM_RECORD/FAM/FAMILY_EVENT_STRUCTURE/HUSB`. Includes may be cyclic:
`NOTE_STRUCTURE` and `SOURCE_CITATION` include each other.

`BLOB` has no value of its own; its value is written in continuation lines only
(`+2 CONT <ENCODED_MULTIMEDIA_LINE>`). A line without a payload whose `CONC`/`CONT` lines have
one gets that payload, so the joined value of `BLOB` is `<ENCODED_MULTIMEDIA_LINE>`.

## Differences from the printed text

Printing errors are corrected; otherwise the grammar is unchanged.

- `{1:1]` and `{0:1*}` are read as `{1:1}` and `{0:1}`.
- `[SUBMITTER_TEXT>` gets its opening `<`, `<NAME_PIECE_SURNAME_PREFIX` its closing `>`.
- The example line `0 @I1@ INDI` after `INDIVIDUAL_RECORD` is not part of the grammar.

## Primitive elements

As in 5.5.1. `SEX_VALUE` is described as "M = Male, F = Female" without brackets; it is read as
the enumeration `[M, F]`. `YEAR_GREG` says "A (B.C.) appended to the <YEAR> indicates a date
before the birth of Christ", so `B.C.` may follow any year: `MAR 44 B.C.` is a valid date.

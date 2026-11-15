# GEDCOM 5.5.1 grammar

`grammar.yaml` is our transcription of the lineage-linked grammar in chapter 2 of
[The GEDCOM Standard, Release 5.5.1](https://gedcom.io/specifications/ged551.pdf)
(15 November 2019 edition, pages 23–40); see `NOTICE`. No machine-readable 5.5.1 grammar with
a license exists, so it is written by hand, line by line in the notation of the specification,
so that it can be compared with the PDF side by side.

## Notation

Each key is a structure of the specification (`FAM_RECORD`, `EVENT_DETAIL`). Its value is a
block of lines, or a list of blocks when the specification gives alternatives `[ … | … ]`.

```
n TAG <PAYLOAD>          {min:max}   a line; n is the level of the structure itself
  +1 TAG @<XREF:INDI>@   {min:max}   a substructure with a pointer payload
  +1 <<OTHER_STRUCTURE>> {min:max}   all top lines of another structure
n [BIRT|CHR] [Y|<NULL>]  {1:1}       one line for each tag of the choice; a choice of payloads
```

Page references and `/* comments */` are left out. `CONC`/`CONT` lines are kept as written;
the compiler skips them because they are syntax, not structures.

## Compilation

`bin/generate-profiles.php` compiles the grammar into `resources/profiles/5.5.1.php`, in the
same shape as the 7.0 profile:

- Every line written in a structure is one profile structure, identified by the structure
  name and its tag path, e.g. `https://gedcom.io/specifications/ged551.pdf#EVENT_DETAIL/DATE`.
  A structure included with `<<NAME>>` keeps its identity, so `INDI.BIRT.DATE` and
  `FAM.MARR.DATE` are the same structure, as in the specification.
- The cardinality of an included line is the product of both: `+1 <<ADDRESS_STRUCTURE>> {0:1}`
  with `n PHON {0:3}` gives `PHON {0:3}`.
- Alternatives with the same tag are merged (`NOTE` as a pointer or as text): payloads are
  joined with `|`, a substructure is required only when every alternative requires it, and an
  alternative without a payload adds `<NULL>`.
- Pointers `@<XREF:INDI>@` become pointers to the URI of the record.
- Top-level records repeat `{0:M}`; `HEAD` and `TRLR` occur once, `SUBN` at most once.

## Differences from the printed text

Only printing errors are corrected; the grammar itself is unchanged, including its known
oddities (the `MULTIMEDIA_RECORD` uses `TYPE` where `MULTIMEDIA_LINK` uses `MEDI`; the
individual `EVEN` has no payload).

- `{0:1)` and `{0:1*` are read as `{0:1}`; the footnote asterisks of `{0:1}*` are dropped.
- Missing cardinalities are `{1:1}`, as for the other alternatives: `RESI` in
  `FAMILY_EVENT_STRUCTURE`, `OBJE` in `MULTIMEDIA_LINK`.
- `<NAME_PIECE_SURNAME_PREFIX` gets its closing `>`.
- The 5.5 form of `MULTIMEDIA_LINK`, which the specification notes "some systems may have
  output", is the third alternative, with `FILE {1:1}`, `FORM {1:1}` and `MEDI {0:1}`.
- The example line `0 @I1@ INDI` after `INDIVIDUAL_RECORD` is not part of the grammar.

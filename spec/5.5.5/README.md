# GEDCOM 5.5.5 grammar

`grammar.yaml` is our transcription of the lineage-linked grammar of
[The GEDCOM 5.5.5 Specification with Annotations](https://www.gedcom.org/specs/GEDCOM555.zip)
(First Release, 2 October 2019, edited by Tamura Jones), and `primitives.yaml` of the
primitive elements it uses; see `NOTICE`. Only grammar lines, enumerations, sizes and value
grammars are transcribed, not the descriptions or the annotations. The notation and the
compilation are those of 5.5.1 (`spec/5.5.1/README.md`); structure URIs start with
`https://www.gedcom.org/specs/GEDCOM555.zip#`.

5.5.5 is transcribed in full rather than as changes to 5.5.1: it restructures most of the
grammar. The file is `HEAD`, exactly one `SUBM` record, other records, `TRLR` (no `SUBN`);
there are no LDS ordinances, no `RESN`, `ALIA`, `ANCI`, `DESI`, `RFN`, `AFN`, no inline
sources, notes or multimedia objects; names and places have phonetic and romanised variants
(`FONE`, `ROMN`); attributes add `FACT`, addresses `ADR3`.

## What 5.5.5 changes in the notation

- The grammar starts at `LINEAGE_LINKED_GEDCOM_FILE`, not `LINEAGE_LINKED_GEDCOM`.
- Pointers and record xrefs are written `<XREF:FAM>`, without `@`; they mean `@<XREF:FAM>@`.
- `FORM LINEAGE-LINKED` has a literal payload: the value must be `LINEAGE-LINKED`.
- A line written in a structure and a line of an included structure may have the same tag:
  every attribute writes `+1 TYPE <USER_REFERENCE_TYPE>` and includes
  `<<INDIVIDUAL_EVENT_DETAIL>>`, whose `<<EVENT_DETAIL>>` has `n TYPE`. The specification
  describes one `TYPE` ("The IDNO and FACT record both require a subordinate TYPE record"); the
  written line takes precedence, so `IDNO` and `FACT` require `TYPE`.
- The byte order mark at the start of the file is a matter of the reader, not of the grammar.

## Differences from the printed text

Printing errors are corrected; otherwise the grammar is unchanged.

- `{0:1)` is read as `{0:1}`; the `MEDI` line without a cardinality is `{0:1}`, like the
  `CALN` above it.
- `<NUMBER_OF_RELATONSHIPS>` is the defined `<NUMBER_OF_RELATIONSHIPS>`;
  `<NAME_PIECE_SURNAME_PREFIX` gets its closing `>`.
- The example line `0 @I1@ INDI` after `ASSOCIATION_STRUCTURE` is not part of the grammar.

## Primitive elements

Unlike 5.5 and 5.5.1, `{Size=min:max}` is a limit: "the minimum and maximum length of the
logical line value". Sizes are kept for text elements and for `NAME_PERSONAL` and
`PLACE_NAME`; dates, ages, times and version numbers are checked by their grammar alone,
because some sizes contradict the grammar (`TIME_VALUE {Size=7:12}` against `hh:mm`).

- `CHARACTER_ENCODING` is `[UTF-8, UNICODE]`: the enumeration also lists ANSEL and ASCII, but
  "the ASCII and ANSEL encoding are not legal in GEDCOM 5.5.5".
- `SEX_VALUE` is described as `M`, `F`, `X`, `U`, `N` without brackets; it is read as an
  enumeration.
- Elements whose values include `<user defined>` (`NAME_TYPE`, `PHONETISATION_METHOD`,
  `ROMANISATION_METHOD`) and lists described in prose (`EVENTS_RECORDED`,
  `EVENT_TYPE_CITED_FROM`, which allows "a user supplied value") are text with a size.
- Grammars implemented in code (`Validation\Rule\Grammar5`, `DateGrammar5`): dates (years of
  3 or 4 digits, days without a leading zero, `BCE|BC|B.C.` after a lone Gregorian or Julian
  year, dual years with a month in both calendars, a Gregorian day and month without a year,
  no `@#DROMAN@`), ages (`< ` and `> ` with a space, parts without zeroes or leading zeroes, at
  most `999y 11m 365d`), times (`h:mm[:ss[.ff]]`), `GEDCOM_VERSION_NUMBER`,
  `PRODUCT_VERSION_NUMBER` and `PLACE_NAME` (parts separated by `, `).
- Rules stated only in prose are not checked: the order of records (`SUBM` directly after
  `HEAD`), no digits in names, dual years only from 1 January to 24 March and up to 1923.

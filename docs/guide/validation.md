# Validation

`Gedcom::validate()` checks a document against the rules of its version and returns a
`ValidationReport`. It never throws for what it finds and never changes the document.

```php
use Korin\Gedcom\Gedcom;

$doc = Gedcom::read('family.ged');
$report = Gedcom::validate($doc);

$report->isValid();                         // no errors (warnings and infos are allowed)
foreach ($report as $diagnostic) {
    echo $diagnostic, PHP_EOL;
    // KV3006 error line 7 (HEAD/CHAR): CHAR does not permit "ANSI"; expected one of …
}
echo count($report->errors()), ' errors, ', count($report->warnings()), ' warnings', PHP_EOL;
```

The report includes the diagnostics of reading. Each diagnostic has a `code`, a `severity`
(`Severity::Error`, `Warning`, `Info`), a `message`, the `line` and `path` it is about, and a
`reference` to the place in the specification where there is one. `json_encode($report)` gives
them as a list.

## What is checked

| Codes | Checks |
| --- | --- |
| `KV1*` | Reading: lines, encodings, HEAD and TRLR, versions, GEDZIP |
| `KV2*` | Structure: which tags may stand where, and how many times |
| `KV3*` | Values: enumerations, dates, ages, names, places, coordinates, languages, media types |
| `KV4*` | References: pointers to missing records, or to records of the wrong type; back pointers |
| `KV5*` | Plausibility, when asked: births after deaths, parents younger than children, cycles |

The structure and value rules of 5.5, 5.5.1 and 5.5.5 come from their specifications, those of
7.0 from the FamilySearch term files. Extension tags (`_UID`) and what is under them are not checked. Every code is described in
[Diagnostics](../diagnostics.md).

## Options

```php
use Korin\Gedcom\Severity;
use Korin\Gedcom\Validation\ValidationOptions;

$options = (new ValidationOptions())
    ->withPlausibility()                    // the KV5* checks
    ->withDisabled('KV1101')                // a code left out
    ->withSeverity('KV3006', Severity::Warning);
$report = Gedcom::validate($doc, $options);
```

Validation works the same on an indexed document (`Gedcom::open()`), record by record, with the
same report; see [Large files](large-files.md).

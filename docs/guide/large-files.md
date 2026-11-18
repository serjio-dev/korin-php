# Large files

`Gedcom::read()` holds the whole tree in memory, packed: a 50 MB file takes about 430 MB. For
files that do not fit, or when one pass is enough, there are three ways that keep memory flat.

| | Memory | Access | Editing |
| --- | --- | --- | --- |
| `Gedcom::stream()` | One record | Records in file order, once | A record at a time, written out as you go |
| `Gedcom::open()` | ~100 bytes per record | Any record, by xref | Read only; `copy()` of a record, `load()` of all |
| `Gedcom::convertFile()` | One record | — | — |

## Streaming

Each record is a document of its own, with the lines and diagnostics `read()` would give it.
The diagnostics of the whole file (encoding, HEAD, TRLR, version) are known after the last one.

```php
use Korin\Gedcom\Gedcom;

$stream = Gedcom::stream('huge.ged');       // a path or an open stream resource
echo $stream->version()->value, ' ', $stream->encoding()->value, PHP_EOL;

$out = fopen('huge-edited.ged', 'wb');
foreach ($stream as $record) {
    if ($record->tag() === 'INDI') {
        $record->node()->first('NAME')?->setValue('Анонім //');   // anonymise
    }
    fwrite($out, Gedcom::serialize($record->document()));         // unchanged records byte for byte
}
fclose($out);
$stream->diagnostics();
```

A streamed record's view knows only its own record: `$record->view()?->familyXrefs()` works,
`parents()` throws `RequiresDocumentException`.

## Indexed reading

`Gedcom::open()` reads the file once to index where each record is, then parses a record from
disk when it is asked for; the last 1000 records are cached.

```php
$doc = Gedcom::open('huge.ged');
echo count($doc), ' records', PHP_EOL;
echo $doc->find('@I1@/NAME')?->value(), PHP_EOL;
foreach ($doc->individual('@I3@')?->parents() ?? [] as $parent) {   // related records read from the file
    echo $parent->name()?->full(), PHP_EOL;
}

$report = Gedcom::validate($doc);           // record by record, the report of the whole document
$family = $doc->record('@F1@')?->document()->copy();   // an editable copy of one record
$whole = $doc->load();                      // or the whole document, as read() gives it
```

Records of an indexed document are read only: editing one throws `ReadOnlyException`.

## Converting

```php
use Korin\Gedcom\Version;

$losses = Gedcom::convertFile('huge.ged', 'huge-7.ged', Version::V7_0);
echo count($losses), ' losses', PHP_EOL;
```

The output and losses are those of `convert()`. Some rules need the whole file (pointers to
records written later), so `convertFile()` reads it two to five times; memory stays at a few
dozen MB for 50 MB.

Times and memory for 10 and 50 MB files are in the
[README](https://github.com/serjio-dev/korin-php#performance).

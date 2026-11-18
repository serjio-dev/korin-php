# Editing and writing

A `Syntax\Document` is the tree of the file: records, and under them structures, each a
`Syntax\Node` with a tag, an optional xref and a value. It is edited in place; `convert()` and
`validate()` never change it.

```php
use Korin\Gedcom\Gedcom;

$doc = Gedcom::read('family.ged');

$name = $doc->find('@I1@/NAME');            // a path of tags from a record; null when there is none
$name?->setValue('Анна /Ковальова/');

$birth = $doc->find('@I3@/BIRT');
$birth?->first('PLAC')?->setValue('Київ, Україна');
$birth?->addChild('NOTE', "Recorded in the parish book.\nPage 12.");   // "\n" becomes CONT

foreach ($doc->find('@F1@')?->all('CHIL') ?? [] as $child) {
    echo $child->pointer(), PHP_EOL;        // @I3@
}
```

Values are the text of the line: `value()` joins CONC and CONT lines and unescapes `@@`,
`setValue()` splits it again by the rules of the version. `pointer()` and `setPointer()` work on
pointers.

## Records and xrefs

```php
$note = $doc->addRecord('NOTE', '@N1@', 'A shared note');   // before TRLR
$doc->find('@I1@')?->addChild('NOTE', '@N1@');               // a pointer to it

$doc->renameXref('@I3@', '@I30@');          // and every pointer to it
$doc->removeRecord('@N1@');                 // the pointer to it is left: validation reports it
```

A removed node is gone from the tree, but node ids do not shift: a `Node` you hold keeps
pointing at the same line, and `isRemoved()` tells it is gone. `$doc->copy()` is a cheap
independent snapshot.

## Preserve and normalize

`Gedcom::write()` writes a file, `Gedcom::serialize()` a string. The default, preserve mode,
writes every line you did not change exactly as it was read: bytes, line endings, spacing, the
lines that are not GEDCOM. Changed and new lines are written by the rules of the version, with
the dominant line ending of the file.

```php
use Korin\Gedcom\WriteOptions;

Gedcom::write($doc, 'family.ged');                           // preserve
Gedcom::write($doc, 'family-clean.ged', WriteOptions::normalize());   // every line anew
```

An unmodified document is written back byte for byte: reading and writing a file is safe even
when Korin does not understand all of it.

## Encodings, line endings, line length

```php
use Korin\Gedcom\Io\Encoding;

$options = WriteOptions::preserve()
    ->withEncoding(Encoding::Utf8)          // unchanged lines are re-encoded, nothing else changes
    ->withLineEnding("\r\n")                // for new and changed lines; every line when normalizing
    ->withBom(false);
file_put_contents('family-utf8.ged', Gedcom::serialize($doc, $options));
```

`withEncoding()` does not change `HEAD.CHAR`: set it yourself, or convert. A character the
encoding cannot hold throws `UnencodableCharacterException`; nothing is ever replaced by `?`.

In 5.x a line has at most 255 characters with its terminator (`withMaxLineLength()`); longer
values are split into CONC lines between words where possible. 7.0 has no CONC and no limit.

## New documents

```php
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

$new = Document::create(Version::V7_0);     // HEAD with GEDC.VERS, and TRLR
$new->addRecord('SNOTE', '@N1@', 'Hello');
echo Gedcom::serialize($new);
```

For individuals, families and events with dates and places, see [The model](model.md).

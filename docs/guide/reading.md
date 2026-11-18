# Reading files

`Gedcom::read()` reads a file, `Gedcom::parse()` a string. Both give a `Syntax\Document`: the
tree of the file, with every line kept, including the lines that are not GEDCOM.

```php
use Korin\Gedcom\Gedcom;

$doc = Gedcom::read('family.ged');
$copy = Gedcom::parse((string) file_get_contents('family.ged'));

echo $doc->version()->value, PHP_EOL;       // 5.5.1
echo $doc->encoding()->value, PHP_EOL;      // Windows-1251
echo count($doc->records()), PHP_EOL;       // HEAD, the records and TRLR
```

## Versions

The version is `HEAD.GEDC.VERS`. Without one the file is read as 5.5.1 (`KV1007`); a version
Korin does not know is read as 5.5.1 too (`KV1008`). GEDCOM 4.0 and the 5.0–5.4 drafts are read
with the rules of 5.5 (`KV1009`, `Version::readAs()`), and can be converted, not written
normalized.

```php
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Version;

$doc = Gedcom::read('family.ged', (new ReadOptions())->withVersion(Version::V5_5));   // whatever it declares
```

## Encodings

The encoding is detected from a byte order mark, `HEAD.CHAR` and the bytes themselves, in that
order. A file that contradicts its `CHAR` is read as its bytes say (`KV1102`): UTF-8 under
`CHAR ANSEL` is UTF-8. Values outside the specifications that programs write (`ANSI`,
`WINDOWS-1251`, `IBMPC`, `MACINTOSH`…) are read as the encoding they name (`KV1101`), and `ANSI`
is Windows-1251 when the text is Cyrillic.

Supported: UTF-8, UTF-16 LE/BE, ANSEL (with combining diacritics), ASCII, Windows-1250, 1251 and
1252, CP437, CP850 and MacRoman. An encoding can be given:

```php
use Korin\Gedcom\Io\Encoding;

$doc = Gedcom::read('family.ged', (new ReadOptions())->withEncoding(Encoding::Windows1251));
```

## Programs and their mistakes

`$doc->sourceSystem()` names the program that wrote the file, from `HEAD.SOUR`. Some programs
are known to declare the wrong version or encoding (PAF 5 declares 5.5 for 5.5.1, Brother's
Keeper writes `CHAR IBMPC` for Windows-1252): Korin reads such files as they are and reports it
(`KV1010`). The list is in [Vendor dialects](../dialects.md).

```php
$system = $doc->sourceSystem();
echo $system?->identifier, ' ', $system?->version(), PHP_EOL;   // Korin 0.5
$plain = Gedcom::read('family.ged', (new ReadOptions())->withDialects(false));
```

## Diagnostics and strict mode

Problems of reading are reported, not thrown: a line that is not a GEDCOM line is kept as it is
(`KV1001`), and so is the rest of the file.

```php
foreach ($doc->diagnostics() as $diagnostic) {
    echo $diagnostic, PHP_EOL;   // KV1101 warning line 7 (HEAD/CHAR): Non-standard CHAR value; …
}
```

In strict mode the first error throws `ParseException`:

```php
Gedcom::read('family.ged', (new ReadOptions())->withStrict());
```

## Limits

Every file is treated as hostile. Reading stops with `ParseException` at a line longer than
`maxLineLength` (1 MiB by default) or nested deeper than `maxDepth` (100):

```php
$options = (new ReadOptions())->withLimits(maxDepth: 32, maxLineLength: 64 * 1024);
```

Messages of diagnostics and exceptions never quote more than a short fragment of the file:
trees hold personal data, and diagnostics end up in logs.

For files that do not fit in memory, see [Large files](large-files.md).

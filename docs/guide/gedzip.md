# GEDZIP archives

A `.gdz` archive (GEDCOM 7.0) is a ZIP file with `gedcom.ged` and the media files its `FILE`
references name. Reading and writing it needs `ext-zip`.

```php
use Korin\Gedcom\Gedcom;

$archive = Gedcom::readGedzip('family.gdz');
$doc = $archive->document();                // gedcom.ged

foreach ($doc->mediaObjects() as $object) {
    foreach ($object->files() as $file) {
        $stream = $archive->media($file->path());   // a stream; nothing is written to disk
        echo $file->path(), ': ', strlen((string) stream_get_contents($stream)), ' bytes', PHP_EOL;
    }
}
$archive->missing();                        // relative references with no entry (KV1204)
$archive->diagnostics();                    // and of the archive's entries
```

`FILE` references are URIs: a space is `%20` in the reference and a space in the entry name.
`$archive->has($reference)` tells whether the archive holds one.

## Writing

```php
use Korin\Gedcom\Version;

$tree = Gedcom::convert(Gedcom::read('family.ged'), Version::V7_0)->document();
Gedcom::writeGedzip($tree, 'family-new.gdz', [
    'photos/anna k.jpg' => 'photos/anna k.jpg',   // entry name => file on disk
]);
```

## Hostile archives

Archives are treated as hostile:

- entries with `..`, absolute paths, backslashes or drive letters, and symbolic links, are
  ignored (`KV1201`, `KV1202`);
- an archive is rejected before anything is decompressed when its entries hold more than
  `ReadOptions::$maxArchiveSize` bytes (4 GiB), or when an entry of more than 1 MiB is compressed
  more than `$maxCompressionRatio` times (100);
- a damaged `gedcom.ged` is a `GedzipException`.

```php
use Korin\Gedcom\ReadOptions;

$archive = Gedcom::readGedzip('family.gdz', (new ReadOptions())->withArchiveLimits(100 * 1024 * 1024, 50));
```

A media stream is the entry as the archive gives it: limit how much you read when the archive
is not yours.

## Large archives

`gedcom.ged` need not be read whole: it can be streamed or converted through PHP's `zip://`
wrapper, decompressed as it is read.

```php
Gedcom::convertFile('zip://family.gdz#gedcom.ged', 'family-551.ged', Version::V5_5_1);
foreach (Gedcom::stream('zip://family.gdz#gedcom.ged') as $record) {
    // …
}
```

`Gedcom::open()` takes a `zip://` path too, but an entry cannot seek: going back to a record
decompresses it again from the start. For random access to a large entry, extract it first.

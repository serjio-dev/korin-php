# Converting between versions

`Gedcom::convert()` converts a document to another version and returns the new document, its
output and a loss report. The input is not changed.

```php
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;

$doc = Gedcom::read('family.ged');          // 5.5.1
$result = Gedcom::convert($doc, Version::V7_0);

file_put_contents('family-7.ged', $result->output());   // in the encoding of the version
$seven = $result->document();               // to edit further
echo count($result->losses()), ' losses', PHP_EOL;
```

Any of 5.5, 5.5.1, 5.5.5 and 7.0 converts to any other, through the versions between them.
GEDCOM 4.0 and the 5.0–5.4 drafts convert from, not to. The output is UTF-8, or UTF-16 for 5.5,
which has no UTF-8.

## Losses

What the target version cannot hold is kept as an extension tag and reported: nothing is dropped
without a report, so a file can be converted, edited and converted back. Each loss has a stable
`KL*` code, the path and line it comes from, and how it was stored.

```php
use Korin\Gedcom\Model\Sex;
use Korin\Gedcom\Syntax\Document;

$tree = Document::create(Version::V7_0);
$tree->addIndividual()->setSex(Sex::Intersex);

$result = Gedcom::convert($tree, Version::V5_5_1);
foreach ($result->losses() as $loss) {
    echo $loss, PHP_EOL;   // KL3002 warning (@I1@/SEX), extension: SEX X has no 5.5.1 value: SEX U
}
echo json_encode($result->losses()), PHP_EOL;   // {"from":"7.0","to":"5.5.1","entries":[…]}
```

The output holds `1 SEX U` with `2 _SEX X` under it. Converting it back to 7.0 restores `SEX X`.

## Policies

```php
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Conversion\LossPolicy;
use Korin\Gedcom\Exception\ConversionException;

$dropped = Gedcom::convert($tree, Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::DropWithReport));

try {
    Gedcom::convert($tree, Version::V5_5_1, (new ConvertOptions())->withPolicy(LossPolicy::Fail));
} catch (ConversionException $e) {
    echo $e->getMessage(), PHP_EOL;         // Conversion stopped by a loss: KL3002 …
}
```

| Policy | What is lost |
| --- | --- |
| `PreserveAsExtension` (default) | Kept as an extension tag, reported |
| `DropWithReport` | Dropped, reported |
| `Fail` | `ConversionException` at the first loss |

Lines that are not GEDCOM lines are dropped under every policy (`KL1001`).

## Files of any size

`Gedcom::convertFile()` converts record by record without reading the whole file, with the
output and losses of `convert()`:

```php
$losses = Gedcom::convertFile('family.ged', 'family-7.ged', Version::V7_0);
```

The rules of each pair of versions are in [Conversion rules](../conversion.md); every loss code
is in [Losses](../losses.md).

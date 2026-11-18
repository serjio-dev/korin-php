# The model

The tree of a document can be read and edited through views of its records: `Individual`,
`Family`, `Source`, `Repository`, `MediaObject`, `SharedNote` and `Submitter`, and the `Event`s
of individuals and families. Views read from the tree and write to it, so whatever they do not
know about stays as it was.

```php
use Korin\Gedcom\Gedcom;

$doc = Gedcom::read('family.ged');

foreach ($doc->individuals() as $person) {
    echo $person->name()?->full(), ', born ', $person->birth()?->date() ?? '?', PHP_EOL;
}

$ivan = $doc->individual('@I3@');
foreach ($ivan?->parents() ?? [] as $parent) {
    echo $parent->name()?->given, PHP_EOL;  // Петро, Анна
}
$ivan?->birth()?->place()?->name();         // "Київ"
$doc->family('@F1@')?->marriage()?->date()?->toGedcom();   // "1946"
$doc->source('@S1@')?->repositories()[0]?->name();          // "Державний архів …"
```

## Building a tree

Linking a family also sets the pointer back: `setWife()` adds `FAMS` to the wife, `addChild()`
adds `FAMC` to the child.

```php
use Korin\Gedcom\Model\{GedcomDate, PersonalName, Place, Sex};
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Version;

$tree = Document::create(Version::V7_0);
$anna = $tree->addIndividual()              // @I1@
    ->setName(PersonalName::of('Анна', 'Коваль'))
    ->setSex(Sex::Female);
$anna->addBirth(GedcomDate::parse('12 MAR 1921'), Place::of('Полтава, Україна'));

$son = $tree->addIndividual()->setName(PersonalName::of('Іван', 'Коваль'));
$tree->addFamily()->setWife($anna)->addChild($son);
echo Gedcom::serialize($tree);
```

## Values

Dates, ages, names, places and coordinates are immutable value objects. They are parsed by the
grammar of a version and written for any version. A value a version cannot write throws
`InvalidValueException` rather than being changed; `tryParse()` returns null instead of
throwing.

```php
use Korin\Gedcom\Model\{Age, Coordinates, DateKind};

$date = GedcomDate::parse('abt @#DJULIAN@ 1650', Version::V5_5_1);
$date->kind === DateKind::About;            // true
$date->date?->calendar;                     // "JULIAN"
$date->toGedcom(Version::V7_0);             // "ABT JULIAN 1650"
GedcomDate::parse('BET 1900 AND 1910')->days();   // Julian Day Numbers of the first and last day
GedcomDate::tryParse('sometime in spring'); // null; in a file it is a date phrase

Age::parse('<1y', Version::V5_5_1)->toGedcom(Version::V7_0);   // "< 1y"
PersonalName::parse('Анна /Коваль/')->withSurname('Ковальова')->toGedcom();   // "Анна /Ковальова/"
Place::of('Полтава, Україна')->parts;      // ["Полтава", "Україна"]
Coordinates::parse('N50.45', 'E30.52')->latitude;   // 50.45
```

## Views of large files

The same views work on an indexed document (`Gedcom::open()`): records they point to are read
from the file when asked for. On a streamed record (`Gedcom::stream()`) a view is limited to its
record: `$record->view()?->familyXrefs()` works, `parents()` throws `RequiresDocumentException`.
See [Large files](large-files.md).

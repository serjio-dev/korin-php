<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support\Conformance;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Spec\Substructure;
use Korin\Gedcom\Version;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Specification conformance cases generated from a profile (SPEC.md section 10, kind 2). Every
 * structure reachable from the top level is placed in a minimal valid file at its shortest
 * path; the file must validate cleanly. Then one thing is broken per case: a tag the structure
 * does not allow, a substructure once more than allowed, a required substructure left out, and
 * the value: one of the wrong type, none where one is required, one where none is allowed, a
 * pointer to the wrong record type, text instead of a pointer and a pointer instead of text.
 *
 * Sample values are written here, independently of the validator's grammars.
 */
final class Cases
{
    /** 7.0 payload types: a valid value and, when the type restricts its values, an invalid one with its code. */
    private const TYPES_7 = [
        'http://www.w3.org/2001/XMLSchema#string' => ['text', null],
        'https://gedcom.io/terms/v7/type-List#Text' => ['one, two', null],
        'https://gedcom.io/terms/v7/type-Name' => ['Anna /Koval/', ['Anna /Koval/ /Bilyk/', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Age' => ['35y 3m', ['35 years', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Date' => ['6 DEC 1907', ['6 December 1907', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Date#period' => ['FROM 1900 TO 1910', ['1900', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Date#exact' => ['6 DEC 1907', ['DEC 1907', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Time' => ['14:30', ['2:30 PM', 'KV3005']],
        'http://www.w3.org/2001/XMLSchema#nonNegativeInteger' => ['3', ['three', 'KV3005']],
        'http://www.w3.org/2001/XMLSchema#anyURI' => ['https://example.org/tree', ['a b', 'KV3005']],
        'http://www.w3.org/2001/XMLSchema#Language' => ['uk', ['Ukrainian language', 'KV3005']],
        'http://www.w3.org/ns/dcat#mediaType' => ['image/jpeg', ['jpeg', 'KV3005']],
        'https://gedcom.io/terms/v7/type-FilePath' => ['photos/anna.jpg', ['mailto:anna@example.org', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Latitude' => ['N50.45', ['50.45', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Longitude' => ['E30.52', ['30.52', 'KV3005']],
        'https://gedcom.io/terms/v7/type-TagDef' => ['_UUID https://example.org/uuid', ['UUID https://example.org/uuid', 'KV3005']],
        'https://gedcom.io/terms/v7/type-Enum' => [null, ['NOT_AN_OPTION', 'KV3006']],
        'https://gedcom.io/terms/v7/type-List#Enum' => [null, ['NOT_AN_OPTION', 'KV3006']],
        'Y' => ['Y', ['N', 'KV3005']],
    ];

    /**
     * 7.0 types whose payload may be left out: those whose grammar matches the empty string
     * (specification section 1: "1 EVEN" is an EVEN with an empty Text). Written out here, not
     * taken from PayloadGrammar::OPTIONAL: the generated cases check the validator against the
     * specification, so they must not share its list.
     */
    private const OPTIONAL_7 = [
        'http://www.w3.org/2001/XMLSchema#string' => true,
        'https://gedcom.io/terms/v7/type-List#Text' => true,
        'https://gedcom.io/terms/v7/type-Age' => true,
        'https://gedcom.io/terms/v7/type-Date' => true,
        'https://gedcom.io/terms/v7/type-Date#period' => true,
    ];

    /** 5.x grammars of primitive elements: a valid value and an invalid one, with the versions that check it. */
    private const GRAMMARS_5 = [
        'AGE_AT_EVENT' => ['35y 3m', ['35 years', 'KV3005']],
        'DATE_VALUE' => ['6 DEC 1907', ['6 December 1907', 'KV3005']],
        'DATE_PERIOD' => ['FROM 1900 TO 1910', ['1900', 'KV3005']],
        'DATE_EXACT' => ['6 DEC 1907', ['DEC 1907', 'KV3005']],
        'TIME_VALUE' => ['14:30', ['2:30 PM', 'KV3005']],
        'NUMBER' => ['3', ['three', 'KV3005']],
        'NAME_PERSONAL' => ['Anna /Koval/', ['Anna /Koval/ /Bilyk/', 'KV3005']],
        'PLACE_LATITUDE' => ['N50.45', ['50.45', 'KV3005']],
        'PLACE_LONGITUDE' => ['E30.52', ['30.52', 'KV3005']],
        'PLACE_NAME' => ['Kyiv, Ukraine', ['Kyiv,Ukraine', 'KV3005', '5.5.5']],
        'GEDCOM_VERSION_NUMBER' => ['5.5.5', ['5.5.5 beta', 'KV3005', '5.5.5']],
        'PRODUCT_VERSION_NUMBER' => ['1.0', ['1.0 beta', 'KV3005', '5.5.5']],
    ];

    /**
     * 5.x structures the specification gives in two forms, which the profile merges (spec/5.5.1/README.md):
     * by version, the substructures of the pointer form and of the other one, with their minimum. A random
     * file keeps to one form; "FILE/FORM" is a FORM required under each FILE.
     */
    private const FORMS_5 = [
        '5.5' => [
            'MULTIMEDIA_LINK/OBJE' => [[], ['FILE' => 1, 'FORM' => 1, 'TITL' => 0, 'NOTE' => 0]],
            'SOURCE_CITATION/SOUR' => [['PAGE' => 0, 'EVEN' => 0, 'DATA' => 0, 'QUAY' => 0, 'OBJE' => 0, 'NOTE' => 0], ['TEXT' => 0, 'NOTE' => 0]],
        ],
        '5.5.1' => [
            'MULTIMEDIA_LINK/OBJE' => [[], ['FILE' => 1, 'FILE/FORM' => 1, 'TITL' => 0]],
            'SOURCE_CITATION/SOUR' => [['PAGE' => 0, 'EVEN' => 0, 'DATA' => 0, 'QUAY' => 0, 'OBJE' => 0, 'NOTE' => 0], ['TEXT' => 0, 'OBJE' => 0, 'NOTE' => 0, 'QUAY' => 0]],
        ],
    ];

    /** 5.x elements the specification restricts in prose only, which the profile reads as free text. */
    private const SAMPLES_5 = ['<EVENT_TYPE_CITED_FROM>' => 'BIRT', '<EVENTS_RECORDED>' => 'BIRT, DEAT'];

    /** Free texts of a random file. */
    private const TEXTS = ['text', 'Kyiv', 'one two  three', 'x', '50%', 'a@b.org'];

    /** Standard tags tried in turn as a substructure that the structure does not allow. */
    private const STRANGERS = ['DATE', 'PLAC', 'NAME', 'SEX', 'NOTE', 'TYPE'];

    /** Values the file's header must keep, by path. */
    private const HEADER = ['HEAD/GEDC/FORM' => 'LINEAGE-LINKED'];

    /** 5.x payloads are continued with CONC beyond this length. */
    private const CHUNK = 200;

    private readonly bool $requiresContent;

    /** @var array<string, string> record URI => xref of its record in the skeleton */
    private array $xrefs = [];

    /** @var array<string, list<string>> structure URI => shortest path of tags */
    private array $paths = [];

    /** Set for random documents (SPEC.md section 10, kind 6): optional structures and values are drawn. */
    private ?Randomizer $random = null;

    /** @var array<string, list<string>> record URI => xrefs of its records in a random document */
    private array $pool = [];

    private function __construct(private readonly Profile $profile)
    {
        $version = $profile->version();
        $this->requiresContent = $version === Version::V7_0 || $version === Version::V5_5_5;
        foreach ($profile->records() as $tag => $record) {
            if ($tag !== 'HEAD' && $tag !== 'TRLR') {
                $this->xrefs[$record->uri] = "@{$tag}1@";
            }
        }
        $this->findPaths();
    }

    /**
     * @return iterable<string, array{Version, string, list<string>}> name => version, file, expected
     *         diagnostics as "CODE line N" ("CODE file" without a line), sorted
     */
    public static function of(Profile $profile): iterable
    {
        $cases = new self($profile);
        foreach ($cases->paths as $uri => $path) {
            yield from $cases->structureCases($uri, $path);
        }
        yield from $cases->rootCases();
    }

    /**
     * A random valid file: the skeleton of the cases with more records and, at every level, optional
     * substructures, each value drawn from the valid ones of its type. The same seed gives the same file.
     */
    public static function random(Profile $profile, int $seed): string
    {
        $cases = new self($profile);
        $cases->random = $random = new Randomizer(new Mt19937($seed));
        foreach ($profile->records() as $tag => $record) {
            if (isset($cases->xrefs[$record->uri])) {
                $count = $random->getInt(1, min(3, $record->cardinality->max ?? 3));
                for ($i = 1; $i <= $count; ++$i) {
                    $cases->pool[$record->uri][] = "@{$tag}{$i}@";
                }
            }
        }
        $file = [];
        foreach ($profile->records() as $tag => $record) {
            foreach ($cases->pool[$record->uri] ?? [null] as $xref) {
                $file[] = $cases->instance($record, $tag === 'HEAD' ? 'HEAD' : null, $xref);
            }
        }

        $cases->mirrorFamilyLinks($file);
        // 5.5.5 requires a byte order mark (KV1104).
        $bom = $profile->version() === Version::V5_5_5 ? "\u{FEFF}" : '';

        return $bom . $cases->case($cases->trailerLast($file), [])[1];
    }

    /**
     * Family links drawn at random point one way only (KV4004): every HUSB, WIFE and CHIL of a family gets
     * its FAMS or FAMC, a FAMC its CHIL, and a FAMS no HUSB or WIFE points back to is left out.
     *
     * @param list<Line> $file
     */
    private function mirrorFamilyLinks(array $file): void
    {
        $records = [];
        foreach ($file as $record) {
            if ($record->xref !== null) {
                $records[$record->xref] = $record;
            }
        }
        $points = static fn(Line $from, string $tags, string $to): bool => array_filter($from->children, static fn(Line $l): bool => str_contains($tags, $l->tag) && $l->value === $to) !== [];
        foreach ($file as $record) {
            if ($record->tag === 'INDI' && $record->xref !== null) {
                $record->children = array_values(array_filter($record->children, static fn(Line $l): bool => $l->tag !== 'FAMS' || $points($records[(string) $l->value], 'HUSB WIFE', $record->xref)));
                foreach ($record->children as $link) {
                    if ($link->tag === 'FAMC' && !$points($family = $records[(string) $link->value], 'CHIL', $record->xref)) {
                        $family->children[] = $this->link('FAM', 'CHIL', $record->xref);
                    }
                }
            }
        }
        foreach ($file as $family) {
            if ($family->tag !== 'FAM' || $family->xref === null) {
                continue;
            }
            foreach ($family->children as $link) {
                $tag = match ($link->tag) {
                    'HUSB', 'WIFE' => 'FAMS',
                    'CHIL' => 'FAMC',
                    default => null,
                };
                if ($tag !== null && !$points($individual = $records[(string) $link->value], $tag, $family->xref)) {
                    $individual->children[] = $this->link('INDI', $tag, $family->xref);
                }
            }
        }
    }

    /** A substructure of a record with its required substructures, pointing to a record. */
    private function link(string $record, string $tag, string $xref): Line
    {
        $substructure = $this->structure((string) $this->profile->record($record)?->uri)->substructure($tag) ?? throw new \LogicException("{$record}.{$tag}");
        $line = $this->instance($substructure);
        $line->value = $xref;

        return $line;
    }

    /**
     * @param list<string> $path
     *
     * @return iterable<string, array{Version, string, list<string>}>
     */
    private function structureCases(string $uri, array $path): iterable
    {
        $structure = $this->structure($uri);
        $name = $this->profile->version()->value . ' ' . implode('/', $path);

        [$file, $target] = $this->file($path);
        yield "{$name}: valid" => $this->case($file, []);

        foreach (self::STRANGERS as $tag) {
            if ($structure->substructure($tag) === null) {
                [$file, $target] = $this->file($path);
                $target->children[] = $stranger = new Line($tag, 'text');
                yield "{$name}: {$tag} not allowed" => $this->case($file, [['KV2001', $stranger]]);
                break;
            }
        }

        foreach ($structure->substructures as $tag => $substructure) {
            // 5.x: a substructure of the inline form only is tried there, by that form's cardinality.
            $inline = $structure->forms !== [] && !isset($structure->forms['pointer'][$tag]);
            yield from $this->cardinalityCases($name, $path, $tag, $inline ? $structure->forms['inline'][$tag] : $substructure, $inline);
        }

        if (!str_starts_with(implode('/', $path), 'HEAD/GEDC') && $path !== ['HEAD', 'CHAR']) {
            yield from $this->valueCases($name, $path, $structure);
        }
    }

    /**
     * @param list<string> $path
     *
     * @return iterable<string, array{Version, string, list<string>}>
     */
    private function cardinalityCases(string $name, array $path, string $tag, Substructure $substructure, bool $inline = false): iterable
    {
        $cardinality = $substructure->cardinality;
        if ($cardinality->max !== null) {
            [$file, $target] = $this->file($path, $inline);
            $count = count(array_filter($target->children, static fn(Line $child): bool => $child->tag === $tag));
            $extra = null;
            for (; $count <= $cardinality->max; ++$count) {
                $target->children[] = $extra = $this->instance($substructure);
            }
            yield "{$name}: {$tag} more than {$cardinality->max} time(s)" => $this->case($file, [['KV2002', $extra]]);
        }
        if ($cardinality->isRequired()) {
            [$file, $target] = $this->file($path, $inline);
            $target->withoutChildren($tag);
            // Left empty, the structure is not also KV3007: the missing substructure is the problem.
            yield "{$name}: without {$tag}" => $this->case($file, [['KV2003', $target]]);
        }
    }

    /**
     * @param list<string> $path
     *
     * @return iterable<string, array{Version, string, list<string>}>
     */
    private function valueCases(string $name, array $path, Structure $structure): iterable
    {
        $alternatives = $structure->payload === null ? [] : explode('|', $structure->payload);
        $nullable = in_array('<NULL>', $alternatives, true);
        $texts = array_values(array_filter($alternatives, static fn(string $a): bool => $a !== '<NULL>' && !str_starts_with($a, '@<')));
        $target = $structure->pointerTarget();

        if ($alternatives === []) {
            [$file, $line] = $this->file($path);
            $line->value = 'text';
            yield "{$name}: a value" => $this->case($file, [['KV3002', $line]]);

            return;
        }

        if (!$nullable && ($this->profile->version() !== Version::V7_0 || array_filter($texts, static fn(string $t): bool => isset(self::OPTIONAL_7[$t])) === [])) {
            [$file, $line] = $this->file($path);
            $line->value = null;
            $code = $this->requiresContent && $line->children === [] && !$this->requiresAny($structure) ? 'KV3007' : 'KV3001';
            yield "{$name}: no value" => $this->case($file, [[$code, $line]]);
        }

        if ($target !== null) {
            $wrong = array_values(array_diff_key($this->xrefs, [$target => true]))[0];
            [$file, $line] = $this->file($path);
            $line->value = $wrong;
            yield "{$name}: pointer to another record type" => $this->case($file, [['KV4003', $line]]);
            if ($texts === []) {
                [$file, $line] = $this->file($path);
                $line->value = 'text';
                yield "{$name}: text instead of a pointer" => $this->case($file, [['KV3003', $line]]);
            }
        } else {
            [$file, $line] = $this->file($path);
            $line->value = $this->xrefs[array_key_first($this->xrefs)];
            yield "{$name}: pointer instead of a value" => $this->case($file, [['KV3004', $line]]);
        }

        if (count($texts) === 1 && ($invalid = $this->invalid($texts[0])) !== null) {
            [$file, $line] = $this->file($path);
            $line->value = $invalid[0];
            yield "{$name}: invalid value" => $this->case($file, [[$invalid[1], $line]]);
        }
    }

    /** @return iterable<string, array{Version, string, list<string>}> */
    private function rootCases(): iterable
    {
        $name = $this->profile->version()->value . ' top level';
        [$file] = $this->file([]);
        $file[] = $stranger = new Line('DATE', '6 DEC 1907', '@X1@');
        yield "{$name}: DATE not allowed" => $this->case($this->trailerLast($file), [['KV2001', $stranger]]);

        foreach ($this->profile->records() as $tag => $record) {
            if ($tag === 'HEAD' || $tag === 'TRLR') {
                continue;
            }
            if ($record->cardinality->isRequired()) {
                [$file] = $this->file([]);
                $file = array_values(array_filter($file, static fn(Line $line): bool => $line->tag !== $tag));
                yield "{$name}: without {$tag}" => $this->case($file, [['KV2003', null]]);
            }
        }
    }

    /**
     * A minimal valid file with the structure at the path: the header, one record of every type
     * (pointers point to them) and the trailer.
     *
     * @param list<string> $path
     *
     * @return array{list<Line>, Line}
     */
    private function file(array $path, bool $inline = false): array
    {
        $file = [];
        foreach ($this->profile->records() as $tag => $record) {
            $file[] = $this->instance($record, $tag === 'HEAD' ? 'HEAD' : null, $this->xrefs[$record->uri] ?? null);
        }
        $line = $file[0];
        if ($path !== []) {
            $line = array_values(array_filter($file, static fn(Line $l): bool => $l->tag === $path[0]))[0];
        }
        $uri = $path === [] ? null : $this->profile->record($path[0])?->uri;
        foreach (array_slice($path, 1) as $i => $tag) {
            $structure = $this->structure((string) $uri);
            $substructure = $structure->substructure($tag) ?? throw new \LogicException(implode('/', $path));
            if ($structure->forms !== [] && !isset($structure->forms['pointer'][$tag])) {
                $this->inline($line, $structure, implode('/', array_slice($path, 0, $i + 1)));
            }
            $uri = $substructure->uri;
            $child = $line->child($tag);
            if ($child === null) {
                $line->children[] = $child = $this->instance($substructure, implode('/', array_slice($path, 0, $i + 2)));
            }
            $line = $child;
        }
        if ($inline) {
            $this->inline($line, $this->structure((string) $uri), implode('/', $path));
        }

        return [$this->trailerLast($file), $line];
    }

    /** A 5.x line in the inline form of its structure: a description (SOUR) or no value (OBJE), and that form's substructures. */
    private function inline(Line $line, Structure $structure, string $path): void
    {
        $line->value = null;
        foreach (explode('|', (string) $structure->payload) as $alternative) {
            if ($alternative !== '<NULL>' && !str_starts_with($alternative, '@<')) {
                $line->value = $this->sample($structure, $alternative);
                break;
            }
        }
        $form = $structure->forms['inline'];
        $line->children = array_values(array_filter($line->children, static fn(Line $child): bool => isset($form[$child->tag])));
        foreach ($form as $tag => $substructure) {
            for ($i = count(array_filter($line->children, static fn(Line $child): bool => $child->tag === $tag)); $i < $substructure->cardinality->min; ++$i) {
                $line->children[] = $this->instance($substructure, "{$path}/{$tag}");
            }
        }
    }

    /**
     * The structure with a valid value and its required substructures; one optional
     * substructure when the version needs content and there would be none.
     */
    /** @param array<string, int> $minimums substructures a random file needs here, with their minimum */
    private function instance(Substructure $substructure, ?string $path = null, ?string $xref = null, int $depth = 0, array $minimums = []): Line
    {
        $structure = $this->structure($substructure->uri);
        $line = new Line($substructure->tag, $this->valid($structure, $path), $xref);
        $form = $this->form($substructure, $line);
        foreach ($structure->substructures as $tag => $child) {
            if ($form !== null && !isset($form[$tag])) {
                continue;
            }
            $inner = [];
            foreach ($form ?? [] as $key => $min) {
                if (str_starts_with($key, "{$tag}/")) {
                    $inner[substr($key, strlen($tag) + 1)] = $min;
                }
            }
            $count = max($form[$tag] ?? 0, $minimums[$tag] ?? 0, $this->count($child, $path === null ? null : "{$path}/{$tag}", $depth));
            for ($i = 0; $i < $count; ++$i) {
                $line->children[] = $this->instance($child, $path === null ? null : "{$path}/{$tag}", null, $depth + 1, $inner);
            }
        }
        // A 7.0 <<NOTE_STRUCTURE>> {0:1} is one NOTE or one SNOTE; the term files list both {0:1}.
        if ($this->random !== null && $structure->substructure('NOTE')?->cardinality->max === 1 && $line->child('NOTE') !== null) {
            $line->withoutChildren('SNOTE');
        }
        if ($this->requiresContent && $line->value === null && $line->children === [] && $substructure->tag !== 'TRLR' && $depth < 8) {
            $child = $this->filler($structure);
            $line->children[] = $this->instance($child, $path === null ? null : "{$path}/{$child->tag}", null, $depth + 1);
        }

        return $line;
    }

    /**
     * In a random 5.x file, the form a structure of FORMS_5 keeps to, drawn: its substructures with their
     * minimum. The value of the line follows the form: a pointer, or a description (SOUR) or none (OBJE).
     *
     * @return ?array<string, int>
     */
    private function form(Substructure $substructure, Line $line): ?array
    {
        $forms = self::FORMS_5[$this->profile->version()->value][substr((string) strrchr($substructure->uri, '#'), 1)] ?? null;
        if ($this->random === null || $forms === null) {
            return null;
        }
        if ($this->random->getInt(0, 1) === 1) {
            return $forms[0];
        }
        $line->value = $line->tag === 'SOUR' ? $this->text() : null;

        return $forms[1];
    }

    /** How many times a substructure is there: its minimum, and in a random file sometimes more, fewer the deeper. */
    private function count(Substructure $substructure, ?string $path, int $depth): int
    {
        $cardinality = $substructure->cardinality;
        if ($this->random === null || $depth >= 6 || str_starts_with((string) $path, 'HEAD/GEDC') || $path === 'HEAD/CHAR'
            || $this->random->getInt(1, 2 * ($depth + 1)) > 1) {
            return $cardinality->min;
        }

        return $this->random->getInt(max(1, $cardinality->min), min($cardinality->max ?? 3, max(1, $cardinality->min) + 2));
    }

    /** The substructure that gives content to an empty structure: one with a text value if there is one. */
    private function filler(Structure $structure): Substructure
    {
        $best = null;
        foreach ($structure->substructures as $substructure) {
            $payload = $this->structure($substructure->uri)->payload;
            $rank = match (true) {
                $payload !== null && !str_contains($payload, '@<') => 0,
                $payload !== null => 1,
                default => 2,
            };
            if ($best === null || $rank < $best[0]) {
                $best = [$rank, $substructure];
            }
        }

        return $best[1] ?? throw new \LogicException("{$structure->uri} can have no content");
    }

    private function valid(Structure $structure, ?string $path): ?string
    {
        if ($path === 'HEAD/GEDC/VERS') {
            return $this->profile->version()->value;
        }
        if ($path === 'HEAD/CHAR') {
            // 5.5 has no UTF-8; the generated files are ASCII.
            return $this->profile->version() === Version::V5_5 ? 'ASCII' : 'UTF-8';
        }
        if ($path !== null && isset(self::HEADER[$path])) {
            return self::HEADER[$path];
        }
        if ($structure->payload === null) {
            return null;
        }
        $target = $structure->pointerTarget();
        if ($target !== null) {
            return $this->random === null ? $this->xrefs[$target] : $this->pick($this->pool[$target]);
        }
        foreach (explode('|', $structure->payload) as $alternative) {
            if ($alternative !== '<NULL>') {
                return $this->sample($structure, $alternative);
            }
        }

        return null;
    }

    private function sample(Structure $structure, string $type): string
    {
        if ($this->profile->version() === Version::V7_0) {
            $sample = (self::TYPES_7[$type] ?? throw new \LogicException("No sample for {$type}"))[0];
            if ($sample === null) {
                return $this->pick($this->profile->enumeration((string) $structure->enumerationSet));
            }

            return $type === 'http://www.w3.org/2001/XMLSchema#string' ? $this->text() : $sample;
        }
        if (!str_starts_with($type, '<') || isset(self::SAMPLES_5[$type])) {
            return self::SAMPLES_5[$type] ?? $type;
        }
        $kind = $this->profile->type($type);
        if ($kind === 'ENUM') {
            $first = $this->pick($this->profile->enumeration($type));

            return str_starts_with($first, '(') ? '(friend)' : $first;
        }
        if ($kind !== null) {
            return self::GRAMMARS_5[$kind][0];
        }
        $size = $this->profile->size($type);
        if ($size === null) {
            return $this->text();
        }
        $text = $this->text();

        return strlen($text) >= $size[0] && strlen($text) <= $size[1] ? $text : str_pad('', max($size[0], min(4, $size[1])), 'text');
    }

    /** A free text: "text", and in a random file also one of other texts. */
    private function text(): string
    {
        return $this->random === null ? 'text' : $this->pick(self::TEXTS);
    }

    /**
     * @param list<string> $values
     */
    private function pick(array $values): string
    {
        return $this->random === null ? $values[0] : $values[$this->random->getInt(0, count($values) - 1)];
    }

    /** @return ?array{string, string} an invalid value of the type and the code it gets */
    private function invalid(string $type): ?array
    {
        $version = $this->profile->version();
        if ($version === Version::V7_0) {
            return self::TYPES_7[$type][1] ?? null;
        }
        if ($type === 'Y') {
            return ['N', 'KV3005'];
        }
        if (!str_starts_with($type, '<')) {
            return ['NOT_AN_OPTION', 'KV3006'];
        }
        $kind = $this->profile->type($type);
        if ($kind === 'ENUM') {
            return ['NOT_AN_OPTION', 'KV3006'];
        }
        if ($kind !== null) {
            $invalid = self::GRAMMARS_5[$kind][1];

            return !isset($invalid[2]) || $invalid[2] === $version->value ? [$invalid[0], $invalid[1]] : null;
        }
        $size = $this->profile->size($type);

        return $size === null ? null : [str_repeat('a', $size[1] + 1), 'KV3010'];
    }

    /**
     * @param list<Line> $file
     * @param list<array{string, ?Line}> $expected
     *
     * @return array{Version, string, list<string>}
     */
    private function case(array $file, array $expected): array
    {
        $numbers = [];
        $text = '';
        $number = 0;
        $write = function (Line $line, int $level) use (&$write, &$numbers, &$text, &$number): void {
            $numbers[spl_object_id($line)] = ++$number;
            $value = $line->value;
            $rest = [];
            if ($value !== null && strlen($value) > self::CHUNK && $this->profile->version() !== Version::V7_0) {
                $rest = str_split(substr($value, self::CHUNK), self::CHUNK);
                $value = substr($value, 0, self::CHUNK);
            }
            $text .= $level . ($line->xref === null ? '' : " {$line->xref}") . " {$line->tag}" . ($value === null ? '' : " {$value}") . "\n";
            foreach ($rest as $part) {
                ++$number;
                $text .= ($level + 1) . " CONC {$part}\n";
            }
            foreach ($line->children as $child) {
                $write($child, $level + 1);
            }
        };
        foreach ($file as $line) {
            $write($line, 0);
        }
        $codes = array_map(static fn(array $e): string => $e[0] . ($e[1] === null ? ' file' : ' line ' . $numbers[spl_object_id($e[1])]), $expected);
        sort($codes);

        return [$this->profile->version(), $text, $codes];
    }

    /**
     * HEAD first, then SUBM (5.5.5 wants it there), the other records, and TRLR last.
     *
     * @param list<Line> $file
     *
     * @return list<Line>
     */
    private function trailerLast(array $file): array
    {
        $rank = static fn(Line $line): int => match ($line->tag) {
            'HEAD' => 0,
            'SUBM' => 1,
            'TRLR' => 3,
            default => 2,
        };
        usort($file, static fn(Line $a, Line $b): int => $rank($a) <=> $rank($b));

        return $file;
    }

    private function findPaths(): void
    {
        $queue = [];
        foreach ($this->profile->records() as $tag => $record) {
            $queue[] = [$record->uri, [$tag]];
        }
        while ($queue !== []) {
            [$uri, $path] = array_shift($queue);
            if (isset($this->paths[$uri])) {
                continue;
            }
            $this->paths[$uri] = $path;
            foreach ($this->structure($uri)->substructures as $tag => $substructure) {
                $queue[] = [$substructure->uri, [...$path, $tag]];
            }
        }
    }

    private function requiresAny(Structure $structure): bool
    {
        foreach ($structure->substructures as $substructure) {
            if ($substructure->cardinality->isRequired()) {
                return true;
            }
        }

        return false;
    }

    private function structure(string $uri): Structure
    {
        return $this->profile->structure($uri) ?? throw new \LogicException("No structure {$uri}");
    }
}

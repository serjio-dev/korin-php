<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support\Conformance;

use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Spec\Substructure;
use Korin\Gedcom\Version;

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

    /** 7.0 types whose payload may be left out. */
    private const OPTIONAL_7 = [
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
            yield from $this->cardinalityCases($name, $path, $tag, $substructure);
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
    private function cardinalityCases(string $name, array $path, string $tag, Substructure $substructure): iterable
    {
        $cardinality = $substructure->cardinality;
        if ($cardinality->max !== null) {
            [$file, $target] = $this->file($path);
            $count = count(array_filter($target->children, static fn(Line $child): bool => $child->tag === $tag));
            $extra = null;
            for (; $count <= $cardinality->max; ++$count) {
                $target->children[] = $extra = $this->instance($substructure);
            }
            yield "{$name}: {$tag} more than {$cardinality->max} time(s)" => $this->case($file, [['KV2002', $extra]]);
        }
        if ($cardinality->isRequired()) {
            [$file, $target] = $this->file($path);
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
    private function file(array $path): array
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
            $substructure = $this->structure((string) $uri)->substructure($tag) ?? throw new \LogicException(implode('/', $path));
            $uri = $substructure->uri;
            $child = $line->child($tag);
            if ($child === null) {
                $line->children[] = $child = $this->instance($substructure, implode('/', array_slice($path, 0, $i + 2)));
            }
            $line = $child;
        }

        return [$this->trailerLast($file), $line];
    }

    /**
     * The structure with a valid value and its required substructures; one optional
     * substructure when the version needs content and there would be none.
     */
    private function instance(Substructure $substructure, ?string $path = null, ?string $xref = null, int $depth = 0): Line
    {
        $structure = $this->structure($substructure->uri);
        $line = new Line($substructure->tag, $this->valid($structure, $path), $xref);
        foreach ($structure->substructures as $tag => $child) {
            for ($i = 0; $i < $child->cardinality->min; ++$i) {
                $line->children[] = $this->instance($child, $path === null ? null : "{$path}/{$tag}", null, $depth + 1);
            }
        }
        if ($this->requiresContent && $line->value === null && $line->children === [] && $substructure->tag !== 'TRLR' && $depth < 8) {
            $child = $this->filler($structure);
            $line->children[] = $this->instance($child, $path === null ? null : "{$path}/{$child->tag}", null, $depth + 1);
        }

        return $line;
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
            return $this->xrefs[$target];
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
                return $this->profile->enumeration((string) $structure->enumerationSet)[0];
            }

            return $sample;
        }
        if (!str_starts_with($type, '<')) {
            return $type;
        }
        $kind = $this->profile->type($type);
        if ($kind === 'ENUM') {
            $first = $this->profile->enumeration($type)[0];

            return str_starts_with($first, '(') ? '(friend)' : $first;
        }
        if ($kind !== null) {
            return self::GRAMMARS_5[$kind][0];
        }
        $size = $this->profile->size($type);

        return $size === null ? 'text' : str_pad('', max($size[0], min(4, $size[1])), 'text');
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

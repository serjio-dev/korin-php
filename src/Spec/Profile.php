<?php

declare(strict_types=1);

namespace Korin\Gedcom\Spec;

use Korin\Gedcom\Version;

/**
 * The rules of one GEDCOM version as data: which structures exist, where they may appear and
 * how often, their payload types, enumerations and calendars. Compiled from spec/ into
 * resources/profiles by bin/generate-profiles.php.
 *
 * @phpstan-type ProfileData array{
 *     version: string,
 *     source: string,
 *     root: array<string, array{string, int, int}>,
 *     structures: array<string, array{tag: string, payload: ?string, enumset: ?string, children: array<string, array{string, int, int}>, forms?: array{pointer: array<string, array{string, int, int}>, inline: array<string, array{string, int, int}>}}>,
 *     enumsets: array<string, list<string>>,
 *     types: array<string, string>,
 *     sizes: array<string, array{int, int}>,
 *     listed?: array<string, list<string>>,
 *     calendars: array<string, array{uri: string, months: list<string>, epochs: list<string>}>,
 *     months?: array<string, string>,
 *     deprecated?: array{structures: array<string, string>, values: array<string, array<string, string>>, without: array<string, string>},
 *     choices?: array<string, list<list<string>>>
 * }
 */
final class Profile
{
    /** @var array<string, self> */
    private static array $loaded = [];

    /** @var array<string, Structure> */
    private array $structures = [];

    /** @param ProfileData $data */
    private function __construct(private readonly array $data) {}

    public static function has(Version $version): bool
    {
        return is_file(self::file($version));
    }

    /** @throws \InvalidArgumentException when there is no profile for the version yet */
    public static function for(Version $version): self
    {
        $version = $version->readAs();
        if (!isset(self::$loaded[$version->value])) {
            if (!self::has($version)) {
                throw new \InvalidArgumentException("No profile for GEDCOM {$version->value} yet");
            }
            /** @var ProfileData $data generated file, shape checked by ProfileTest */
            $data = require self::file($version);
            self::$loaded[$version->value] = new self($data);
        }

        return self::$loaded[$version->value];
    }

    public function version(): Version
    {
        return Version::from($this->data['version']);
    }

    /** Where the profile was compiled from, e.g. "FamilySearch GEDCOM v7.0.18 (…)". */
    public function source(): string
    {
        return $this->data['source'];
    }

    /** A top-level structure (record, HEAD or TRLR) by tag. */
    public function record(string $tag): ?Substructure
    {
        $entry = $this->data['root'][$tag] ?? null;

        return $entry === null ? null : $this->substructureOf($tag, $entry);
    }

    /** @return array<string, Substructure> top-level structures by tag */
    public function records(): array
    {
        $records = [];
        foreach ($this->data['root'] as $tag => $entry) {
            $records[$tag] = $this->substructureOf($tag, $entry);
        }

        return $records;
    }

    public function structure(string $uri): ?Structure
    {
        if (isset($this->structures[$uri])) {
            return $this->structures[$uri];
        }
        $entry = $this->data['structures'][$uri] ?? null;
        if ($entry === null) {
            return null;
        }
        $substructures = [];
        foreach ($entry['children'] as $tag => $child) {
            $substructures[$tag] = $this->substructureOf($tag, $child);
        }
        $forms = [];
        foreach ($entry['forms'] ?? [] as $form => $children) {
            foreach ($children as $tag => $child) {
                $forms[$form][$tag] = $this->substructureOf($tag, $child);
            }
            $forms[$form] ??= [];
        }

        return $this->structures[$uri] = new Structure($uri, $entry['tag'], $entry['payload'], $entry['enumset'], $substructures, $forms);
    }

    /**
     * The structure reached by a path of tags from the top level, e.g. ['INDI', 'BIRT', 'DATE'];
     * null when some tag is not allowed there (an extension tag, or an error).
     *
     * @param list<string> $tags
     */
    public function resolve(array $tags): ?Structure
    {
        $tag = array_shift($tags);
        $uri = $tag === null ? null : $this->record($tag)?->uri;
        foreach ($tags as $tag) {
            $uri = $uri === null ? null : $this->structure($uri)?->substructure($tag)?->uri;
        }

        return $uri === null ? null : $this->structure($uri);
    }

    /**
     * The kind of a 5.x primitive element such as "<SEX_VALUE>": "ENUM" (values from
     * enumeration()) or the name of a grammar such as "DATE_VALUE"; null for text and in 7.0.
     */
    public function type(string $element): ?string
    {
        return $this->data['types'][$element] ?? null;
    }

    /**
     * The minimum and maximum length of a 5.5.5 text element such as "<ADDRESS_CITY>", in
     * characters; null when the version gives none (sizes are recommendations before 5.5.5).
     *
     * @return ?array{int, int}
     */
    public function size(string $element): ?array
    {
        return $this->data['sizes'][$element] ?? null;
    }

    /**
     * Values the specification lists for a 5.5.5 text element with a size, valid whatever their
     * length: the printed size contradicts them ("<NAME_TYPE>" aka, {Size=5:30}).
     *
     * @return list<string>
     */
    public function listed(string $element): array
    {
        return $this->data['listed'][$element] ?? [];
    }

    /** @return list<string> values of an enumeration set (7.0 URI) or of a 5.x element ("<SEX_VALUE>") */
    public function enumeration(string $uri): array
    {
        return $this->data['enumsets'][$uri] ?? [];
    }

    /** @return array<string, array{uri: string, months: list<string>, epochs: list<string>}> by calendar tag */
    public function calendars(): array
    {
        return $this->data['calendars'];
    }

    /** @return array<string, string> 7.0: month URI => tag, of every calendar */
    public function months(): array
    {
        return $this->data['months'] ?? [];
    }

    /**
     * 7.0: what to write instead of a deprecated structure (spec/7.0/deprecations.yaml); '' when
     * the specification names nothing, null when the structure is not deprecated.
     */
    public function deprecation(string $uri): ?string
    {
        return $this->data['deprecated']['structures'][$uri] ?? null;
    }

    /** 7.0: as deprecation(), for a value of an enumeration set. */
    public function deprecatedValue(string $enumerationSet, string $value): ?string
    {
        return $this->data['deprecated']['values'][$enumerationSet][$value] ?? null;
    }

    /**
     * 7.0: groups of substructures of which a structure allows one (spec/7.0/choices.yaml: HEAD has a
     * NOTE or an SNOTE).
     *
     * @return list<list<string>>
     */
    public function choices(string $uri): array
    {
        return $this->data['choices'][$uri] ?? [];
    }

    /** 7.0: the tag of the substructure without which a structure is deprecated (EXID without TYPE). */
    public function deprecatedWithout(string $uri): ?string
    {
        return $this->data['deprecated']['without'][$uri] ?? null;
    }

    /** @param array{string, int, int} $entry */
    private function substructureOf(string $tag, array $entry): Substructure
    {
        return new Substructure($tag, $entry[0], new Cardinality($entry[1], $entry[2] < 0 ? null : $entry[2]));
    }

    private static function file(Version $version): string
    {
        return __DIR__ . "/../../resources/profiles/{$version->readAs()->value}.php";
    }
}

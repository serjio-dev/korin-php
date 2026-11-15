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
 *     structures: array<string, array{tag: string, payload: ?string, enumset: ?string, children: array<string, array{string, int, int}>}>,
 *     enumsets: array<string, list<string>>,
 *     calendars: array<string, array{uri: string, months: list<string>, epochs: list<string>}>
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

        return $this->structures[$uri] = new Structure($uri, $entry['tag'], $entry['payload'], $entry['enumset'], $substructures);
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

    /** @return list<string> standard tags of the values of an enumeration set */
    public function enumeration(string $uri): array
    {
        return $this->data['enumsets'][$uri] ?? [];
    }

    /** @return array<string, array{uri: string, months: list<string>, epochs: list<string>}> by calendar tag */
    public function calendars(): array
    {
        return $this->data['calendars'];
    }

    /** @param array{string, int, int} $entry */
    private function substructureOf(string $tag, array $entry): Substructure
    {
        return new Substructure($tag, $entry[0], new Cardinality($entry[1], $entry[2] < 0 ? null : $entry[2]));
    }

    private static function file(Version $version): string
    {
        return __DIR__ . "/../../resources/profiles/{$version->value}.php";
    }
}

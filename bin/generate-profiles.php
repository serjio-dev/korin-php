<?php

declare(strict_types=1);

/*
 * Compiles the version profiles in spec/ into resources/profiles/*.php, so the library reads
 * no YAML at runtime. Needs symfony/yaml (a dev dependency).
 *
 *   php bin/generate-profiles.php            write the files
 *   php bin/generate-profiles.php --check    exit 1 if a file is out of date
 */

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../vendor/autoload.php';

const PROFILES = [
    '7.0' => [
        'terms' => 'spec/7.0/terms',
        'source' => 'FamilySearch GEDCOM v7.0.18 (2f0b863b5a42275bd2b3ca315900cf779e42f28b)',
    ],
];

/** Records other than HEAD and TRLR may repeat; CONC/CONT are syntax, not structures. */
const ROOT_CARDINALITY = ['HEAD' => [1, 1], 'TRLR' => [1, 1]];
const SYNTAX_TAGS = ['CONC', 'CONT'];

$argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$check = in_array('--check', $argv, true);
$root = dirname(__DIR__);
$stale = [];
foreach (PROFILES as $version => $config) {
    $target = "{$root}/resources/profiles/{$version}.php";
    $code = render($version, $config['source'], compile("{$root}/{$config['terms']}"));
    if ($check) {
        if (!is_file($target) || file_get_contents($target) !== $code) {
            $stale[] = $target;
        }
        continue;
    }
    @mkdir(dirname($target), 0o777, true);
    file_put_contents($target, $code);
    echo "{$target}\n";
}
if ($stale !== []) {
    fwrite(STDERR, "Out of date, run php bin/generate-profiles.php:\n  " . implode("\n  ", $stale) . "\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function compile(string $dir): array
{
    $files = glob($dir . '/*');
    if ($files === false || $files === []) {
        throw new RuntimeException("No term files in {$dir}");
    }
    $terms = [];
    foreach ($files as $file) {
        $term = Term::load($file);
        $terms[$term->uri] = $term;
    }
    ksort($terms);

    $tagOf = static fn(string $uri): string => $terms[$uri]->tag ?? throw new RuntimeException("No tag for {$uri}");
    $structures = [];
    $root = [];
    $enumsets = [];
    $calendars = [];
    foreach ($terms as $uri => $term) {
        switch ($term->type) {
            case 'structure':
                $children = [];
                foreach ($term->substructures as $child => $cardinality) {
                    if (!in_array($tagOf($child), SYNTAX_TAGS, true)) {
                        $children[$tagOf($child)] = [$child, ...cardinality($cardinality)];
                    }
                }
                ksort($children);
                $structures[$uri] = [
                    'tag' => $tagOf($uri),
                    'payload' => $term->payload,
                    'enumset' => $term->enumerationSet,
                    'children' => $children,
                ];
                if ($term->superstructures === [] && !in_array($tagOf($uri), SYNTAX_TAGS, true)) {
                    $root[$tagOf($uri)] = [$uri, ...(ROOT_CARDINALITY[$tagOf($uri)] ?? [0, -1])];
                }
                break;
            case 'enumeration set':
                $values = array_map($tagOf, $term->values);
                sort($values);
                $enumsets[$uri] = $values;
                break;
            case 'calendar':
                $calendars[$tagOf($uri)] = ['uri' => $uri, 'months' => array_map($tagOf, $term->months), 'epochs' => $term->epochs];
                break;
        }
    }
    ksort($root);
    ksort($calendars);

    return ['root' => $root, 'structures' => $structures, 'enumsets' => $enumsets, 'calendars' => $calendars];
}

/** The fields of a term file (https://gedcom.io/terms/format) that profiles use. */
final readonly class Term
{
    /**
     * @param array<string, string> $substructures URI => cardinality
     * @param array<string, string> $superstructures URI => cardinality
     * @param list<string> $values
     * @param list<string> $months
     * @param list<string> $epochs
     */
    private function __construct(
        public string $uri,
        public string $type,
        public ?string $tag,
        public ?string $payload,
        public ?string $enumerationSet,
        public array $substructures,
        public array $superstructures,
        public array $values,
        public array $months,
        public array $epochs,
    ) {}

    public static function load(string $file): self
    {
        $yaml = Yaml::parseFile($file);
        if (!is_array($yaml)) {
            throw new RuntimeException("Not a term file: {$file}");
        }
        $text = static function (string $key) use ($yaml, $file): ?string {
            $value = $yaml[$key] ?? null;

            return $value === null || is_string($value) ? $value : throw new RuntimeException("{$file}: {$key} is not a string");
        };
        $strings = static function (string $key) use ($yaml, $file): array {
            $value = $yaml[$key] ?? [];
            if (!is_array($value) || !array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
                throw new RuntimeException("{$file}: {$key} is not a list of strings");
            }

            return $value;
        };
        $map = static function (string $key) use ($yaml, $file): array {
            $value = $yaml[$key] ?? [];
            $out = [];
            foreach (is_array($value) ? $value : throw new RuntimeException("{$file}: {$key} is not a map") as $k => $v) {
                $out[(string) $k] = is_string($v) ? $v : throw new RuntimeException("{$file}: {$key}.{$k} is not a string");
            }

            return $out;
        };

        return new self(
            $text('uri') ?? throw new RuntimeException("{$file}: no uri"),
            $text('type') ?? throw new RuntimeException("{$file}: no type"),
            $text('standard tag'),
            $text('payload'),
            $text('enumeration set'),
            $map('substructures'),
            $map('superstructures'),
            $strings('enumeration values'),
            $strings('months'),
            $strings('epochs'),
        );
    }
}

/** @return array{int, int} minimum and maximum, -1 for "M" */
function cardinality(string $value): array
{
    if (preg_match('/^\{(\d+):(\d+|M)\}$/', $value, $m) !== 1) {
        throw new RuntimeException("Bad cardinality {$value}");
    }

    return [(int) $m[1], $m[2] === 'M' ? -1 : (int) $m[2]];
}

/** @param array<string, mixed> $profile */
function render(string $version, string $source, array $profile): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\n// Generated by bin/generate-profiles.php from {$source}. Do not edit.\n\nreturn "
        . exportValue(['version' => $version, 'source' => $source] + $profile, 0) . ";\n";
}

/** var_export() with short arrays, one entry per line and lists without keys. */
function exportValue(mixed $value, int $depth): string
{
    if (!is_array($value)) {
        return $value === null ? 'null' : var_export($value, true);
    }
    if ($value === []) {
        return '[]';
    }
    $list = array_is_list($value);
    $scalars = array_filter($value, is_array(...)) === [];
    if ($list && $scalars) {
        return '[' . implode(', ', array_map(static fn(mixed $v): string => exportValue($v, 0), $value)) . ']';
    }
    $pad = str_repeat('    ', $depth + 1);
    $lines = [];
    foreach ($value as $key => $item) {
        $lines[] = $pad . ($list ? '' : var_export($key, true) . ' => ') . exportValue($item, $depth + 1) . ',';
    }

    return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
}

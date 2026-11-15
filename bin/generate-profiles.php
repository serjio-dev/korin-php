<?php

declare(strict_types=1);

/*
 * Compiles the version profiles in spec/ into resources/profiles/*.php, so the library reads
 * no YAML at runtime. 7.0 comes from FamilySearch term files, 5.x from our transcription of the
 * grammar in the specification (spec/<version>/README.md). Needs symfony/yaml (a dev dependency).
 *
 *   php bin/generate-profiles.php            write the files
 *   php bin/generate-profiles.php --check    exit 1 if a file is out of date
 */

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../vendor/autoload.php';

const PROFILES = [
    '5.5.1' => [
        'grammar' => 'spec/5.5.1/grammar.yaml',
        'uri' => 'https://gedcom.io/specifications/ged551.pdf#',
        'source' => 'The GEDCOM Standard, Release 5.5.1 (15 November 2019), chapter 2',
    ],
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
    $profile = isset($config['grammar'])
        ? (new Grammar("{$root}/{$config['grammar']}", $config['uri']))->compile()
        : compile("{$root}/{$config['terms']}");
    $code = render($version, $config['source'], $profile);
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

/**
 * A GEDCOM 5.x lineage-linked grammar in the notation of the specification (see
 * spec/5.5.1/README.md), compiled into the profile shape. Each line written in a named structure
 * is one structure, identified by the name and its tag path ("…#EVENT_DETAIL/DATE"); a structure
 * included with <<NAME>> keeps its own identity. Alternatives with the same tag are merged: a
 * substructure is required only when every alternative requires it.
 */
final class Grammar
{
    private const LINE = '/^(?<indent> *)(?<level>n|0|\+\d) +(?<body>.*?) +\{(?<min>\d+):(?<max>\d+|M)\}$/';
    private const ROOT = 'LINEAGE_LINKED_GEDCOM';

    /** @var array<string, list<list<GrammarLine>>> structure name => alternatives => top lines */
    private array $definitions = [];

    /** @var array<string, array<string, array{string, int, int}>> */
    private array $members = [];

    /** @var array<string, array{tag: string, payloads: list<string>, empty: bool, children: list<array<string, array{string, int, int}>>}> */
    private array $structures = [];

    public function __construct(string $file, private readonly string $base)
    {
        $yaml = Yaml::parseFile($file);
        foreach (is_array($yaml) ? $yaml : throw new RuntimeException("Not a grammar: {$file}") as $name => $value) {
            $blocks = is_string($value) ? [$value] : (is_array($value) ? $value : []);
            foreach ($blocks as $block) {
                $this->definitions[(string) $name][] = $this->parse(is_string($block) ? $block : throw new RuntimeException("{$name}: not a block"), (string) $name);
            }
        }
    }

    /** @return array<string, mixed> */
    public function compile(): array
    {
        $root = $this->members(self::ROOT);
        ksort($root);
        $pointer = (static fn(string $payload): string => preg_replace_callback('/@<XREF:([A-Z]+)>@/', static fn(array $m): string => '@<' . ($root[$m[1]][0] ?? throw new RuntimeException("No record {$m[1]}")) . '>@', $payload) ?? $payload);
        $structures = [];
        foreach ($this->structures as $uri => $structure) {
            $children = $this->merge($structure['children']);
            ksort($children);
            $structures[$uri] = [
                'tag' => $structure['tag'],
                // An alternative without a payload makes the payload optional.
                'payload' => $structure['payloads'] === [] ? null : $pointer(implode('|', [...$structure['payloads'], ...($structure['empty'] && !in_array('<NULL>', $structure['payloads'], true) ? ['<NULL>'] : [])])),
                'enumset' => null,
                'children' => $children,
            ];
        }
        ksort($structures);

        return ['root' => $root, 'structures' => $structures, 'enumsets' => [], 'calendars' => []];
    }

    /** @return array<string, array{string, int, int}> the top lines of a named structure by tag */
    private function members(string $name): array
    {
        if (!isset($this->members[$name])) {
            $alternatives = [];
            foreach ($this->definitions[$name] ?? throw new RuntimeException("Unknown structure <<{$name}>>") as $lines) {
                $alternatives[] = $this->lines($name, '', $lines);
            }
            $this->members[$name] = $this->merge($alternatives);
        }

        return $this->members[$name];
    }

    /**
     * @param list<GrammarLine> $lines
     *
     * @return array<string, array{string, int, int}>
     */
    private function lines(string $name, string $path, array $lines): array
    {
        $out = [];
        $put = static function (string $tag, array $entry) use (&$out, $name): void {
            /** @var array{string, int, int} $entry */
            $out[$tag] = isset($out[$tag]) ? throw new RuntimeException("{$name}: {$tag} twice in one place") : $entry;
        };
        foreach ($lines as $line) {
            if ($line->include !== null) {
                foreach ($this->members($line->include) as $tag => [$uri, $min, $max]) {
                    $put($tag, [$uri, $line->min * $min, $line->max < 0 || $max < 0 ? -1 : $line->max * $max]);
                }
                continue;
            }
            foreach ($line->tags as $tag) {
                $uri = "{$this->base}{$name}{$path}/{$tag}";
                $children = $this->lines($name, "{$path}/{$tag}", $line->children);
                $this->structures[$uri] ??= ['tag' => $tag, 'payloads' => [], 'empty' => false, 'children' => []];
                $this->structures[$uri]['empty'] = $this->structures[$uri]['empty'] || $line->payload === null;
                foreach ($line->payload === null ? [] : explode('|', $line->payload) as $payload) {
                    if (!in_array($payload, $this->structures[$uri]['payloads'], true)) {
                        $this->structures[$uri]['payloads'][] = $payload;
                    }
                }
                $this->structures[$uri]['children'][] = $children;
                $put($tag, [$uri, $line->min, $line->max]);
            }
        }

        return $out;
    }

    /**
     * Alternatives of the same place: a tag is required only if every alternative requires it.
     *
     * @param list<array<string, array{string, int, int}>> $alternatives
     *
     * @return array<string, array{string, int, int}>
     */
    private function merge(array $alternatives): array
    {
        $out = [];
        foreach ($alternatives as $entries) {
            foreach ($entries as $tag => [$uri, , $max]) {
                if (isset($out[$tag]) && $out[$tag][0] !== $uri) {
                    throw new RuntimeException("{$tag} means both {$out[$tag][0]} and {$uri}");
                }
                $old = $out[$tag][2] ?? 0;
                $out[$tag] = [$uri, 0, $old < 0 || $max < 0 ? -1 : max($old, $max)];
            }
        }
        foreach (array_keys($out) as $tag) {
            $min = PHP_INT_MAX;
            foreach ($alternatives as $entries) {
                $min = min($min, $entries[$tag][1] ?? 0);
            }
            $out[$tag][1] = $min;
        }

        return $out;
    }

    /** @return list<GrammarLine> top lines of one block; CONC and CONT lines are left out */
    private function parse(string $block, string $name): array
    {
        $top = [];
        /** @var array<int, GrammarLine> $stack line by level */
        $stack = [];
        foreach (explode("\n", rtrim($block)) as $text) {
            if (preg_match(self::LINE, rtrim($text), $m) !== 1) {
                throw new RuntimeException("{$name}: cannot read \"{$text}\"");
            }
            $level = $m['level'][0] === '+' ? (int) substr($m['level'], 1) : 0;
            $line = GrammarLine::of($m['body'], (int) $m['min'], $m['max'] === 'M' ? -1 : (int) $m['max'], $name);
            if ($line === null) {
                continue;
            }
            if ($level === 0) {
                $top[] = $line;
            } else {
                $superior = $stack[$level - 1] ?? throw new RuntimeException("{$name}: level {$level} without a superior");
                $superior->children[] = $line;
            }
            $stack[$level] = $line;
        }

        return $top;
    }
}

/** One line of a grammar block: a tag (or a choice of tags) with its payload, or <<NAME>>. */
final class GrammarLine
{
    /** @var list<GrammarLine> */
    public array $children = [];

    /** @param list<string> $tags */
    private function __construct(
        public readonly array $tags,
        public readonly ?string $include,
        public readonly ?string $payload,
        public readonly int $min,
        public readonly int $max,
    ) {}

    public static function of(string $body, int $min, int $max, string $name): ?self
    {
        if (preg_match('/^<<([A-Z_]+)>>$/', $body, $m) === 1) {
            return new self([], $m[1], null, $min, $max);
        }
        // Records start with their xref: "@<XREF:FAM>@ FAM".
        if (preg_match('/^(?:@<?XREF:[A-Z]+>?@ +)?(?:\[([A-Z0-9_|]+)\]|([A-Z0-9_]+))(?: +(.+))?$/', $body, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new RuntimeException("{$name}: cannot read \"{$body}\"");
        }
        $tags = explode('|', $m[1] ?? $m[2] ?? '');
        if (array_intersect($tags, ['CONC', 'CONT']) !== []) {
            return null;
        }
        $payload = $m[3] !== null ? preg_replace(['/\s+/', '/^\[(.*)\]$/', '/@XREF:([A-Z]+)@/'], ['', '$1', '@<XREF:$1>@'], $m[3]) : null;

        return new self($tags, null, $payload, $min, $max);
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

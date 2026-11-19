<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * The cases of gedcom-corpus: KORIN_CORPUS, or the dev dependency. An external case (license
 * NOASSERTION, input external.ged or .gdz, listed in the corpus's external.json) has its input
 * only after `python3 <corpus>/scripts/fetch-external.py`; until then, or while the file does not
 * have the SHA-256 of external.json, the suites leave it out and CorpusTest says how many are
 * missing. Any other case is always in, so a missing input fails.
 */
final class Corpus
{
    /** @var array<string, array{array<string, array<string, mixed>>, list<string>}> root => [cases, unfetched] */
    private static array $loaded = [];

    public static function root(): string
    {
        $root = getenv('KORIN_CORPUS');

        return $root === false || $root === '' ? __DIR__ . '/../../vendor/korin/gedcom-corpus' : $root;
    }

    /**
     * @return array<string, array<string, mixed>> case directory => its case.yaml
     *
     * @throws \RuntimeException when there are no cases
     */
    public static function cases(): array
    {
        return self::load()[0];
    }

    /** @return list<string> ids of the external cases whose input is not there, or not the one external.json names */
    public static function unfetched(): array
    {
        return self::load()[1];
    }

    /** @return array{array<string, array<string, mixed>>, list<string>} */
    private static function load(): array
    {
        $root = self::root();
        if (isset(self::$loaded[$root])) {
            return self::$loaded[$root];
        }
        $files = glob($root . '/cases/*/case.yaml');
        if ($files === false || $files === []) {
            throw new \RuntimeException("No corpus cases under {$root}; run composer install or set KORIN_CORPUS");
        }
        $external = is_file($root . '/external.json') ? json_decode((string) file_get_contents($root . '/external.json'), true) : null;
        /** @var array<string, array{sha256: string}> $hashes */
        $hashes = is_array($external) && is_array($external['cases'] ?? null) ? $external['cases'] : [];
        $cases = $unfetched = [];
        foreach ($files as $file) {
            $dir = dirname($file);
            /** @var array{input: array{file: string}} $case */
            $case = Yaml::parseFile($file);
            $input = $dir . '/' . $case['input']['file'];
            if (str_starts_with($case['input']['file'], 'external.')
                && (!is_file($input) || hash_file('sha256', $input) !== ($hashes[basename($dir)]['sha256'] ?? null))) {
                $unfetched[] = basename($dir);
                continue;
            }
            $cases[$dir] = $case;
        }

        return self::$loaded[$root] = [$cases, $unfetched];
    }
}

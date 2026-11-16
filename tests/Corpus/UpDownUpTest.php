<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Corpus;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Tests\Support\Fingerprint;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * SPEC section 10, test type 5: converting every 7.0 input of the corpus to 5.5.1 and back
 * gives the same fingerprint, except for the structures the loss report names (KL3: values
 * 5.5.1 cannot write so that they come back, kept as extensions). Percent-encodings of FILE
 * compare in uppercase, as URLs do.
 */
final class UpDownUpTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function inputs(): iterable
    {
        $root = getenv('KORIN_CORPUS');
        if ($root === false || $root === '') {
            $root = __DIR__ . '/../../vendor/korin/gedcom-corpus';
        }
        $files = glob($root . '/cases/*/case.yaml');
        foreach ($files === false ? [] : $files as $file) {
            /** @var array{input: array{file: string, version: string}} $case */
            $case = Yaml::parseFile($file);
            if ($case['input']['version'] === '7.0' && str_ends_with($case['input']['file'], '.ged')) {
                yield basename(dirname($file)) => [dirname($file) . '/' . $case['input']['file']];
            }
        }
    }

    #[Test]
    #[DataProvider('inputs')]
    public function upDownUpKeepsTheDocument(string $file): void
    {
        $input = Gedcom::parse((string) file_get_contents($file));
        $down = Gedcom::convert($input, Version::V5_5_1);
        $up = Gedcom::convert($down->document(), Version::V7_0)->document()->copy();
        $before = $input->copy();
        foreach ($down->losses()->entries() as $loss) {
            if (!str_starts_with($loss->code, 'KL3')) {
                continue;
            }
            $id = $this->find($before, $loss->path);
            self::assertNotNull($id, "the loss path {$loss->path} names a structure");
            $chain = $this->chain($before, $id);
            $before->removeNode($id);
            $up->removeNode($this->at($up, $chain));
        }

        self::assertSame($this->fingerprint($before), $this->fingerprint($up), 'Losses: ' . implode('; ', array_map(strval(...), $down->losses()->entries())));
    }

    private function fingerprint(Document $document): string
    {
        $json = (string) json_encode(Fingerprint::of($document), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) preg_replace_callback('/("tag": "FILE",\n\s*"value": ")([^"]*)/', static fn(array $m): string => $m[1] . preg_replace_callback('/%[0-9a-f]{2}/i', static fn(array $e): string => strtoupper($e[0]), $m[2]), $json);
    }

    private function find(Document $document, string $path): ?int
    {
        foreach ($document->walk() as $id) {
            if (!$document->isInvalid($id) && $document->node($id)->path() === $path) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<int> the position of a node: its index among its parent's children, from the top */
    private function chain(Document $document, int $id): array
    {
        $chain = [];
        for (; $id !== 0; $id = $document->parentOf($id)) {
            array_unshift($chain, (int) array_search($id, $document->childIds($document->parentOf($id)), true));
        }

        return $chain;
    }

    /** @param list<int> $chain */
    private function at(Document $document, array $chain): int
    {
        $id = 0;
        foreach ($chain as $index) {
            $id = $document->childIds($id)[$index];
        }

        return $id;
    }
}

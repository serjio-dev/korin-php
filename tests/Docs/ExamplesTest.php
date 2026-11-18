<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Docs;

use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PHP examples of the README and the guides (SPEC section 13: examples never go stale).
 * The blocks of one page run in order, as one script, in a directory with the files they name:
 * family.ged (5.5.1, Windows-1251), huge.ged, family.gdz (7.0) and "photos/anna k.jpg". A block
 * after an `<!-- example: skip -->` line is not run.
 */
final class ExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Imports every page may use without showing them. */
    private const PRELUDE = <<<'PHP'
        use Korin\Gedcom\ConvertOptions;
        use Korin\Gedcom\Conversion\LossPolicy;
        use Korin\Gedcom\Gedcom;
        use Korin\Gedcom\Io\Encoding;
        use Korin\Gedcom\Model\Age;
        use Korin\Gedcom\Model\GedcomDate;
        use Korin\Gedcom\Model\PersonalName;
        use Korin\Gedcom\Model\Place;
        use Korin\Gedcom\Model\Sex;
        use Korin\Gedcom\ReadOptions;
        use Korin\Gedcom\Syntax\Document;
        use Korin\Gedcom\Validation\ValidationOptions;
        use Korin\Gedcom\Version;
        use Korin\Gedcom\WriteOptions;
        PHP;

    private string $directory;

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'README.md' => ['README.md'];
        $pages = glob(self::ROOT . '/docs/{index.md,guide/*.md}', GLOB_BRACE);
        foreach ($pages === false ? [] : $pages as $page) {
            $relative = substr($page, strlen(self::ROOT) + 1);
            yield $relative => [$relative];
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/korin-docs-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/photos', 0o777, true);
        copy(__DIR__ . '/fixtures/family.ged', $this->directory . '/family.ged');
        copy(__DIR__ . '/fixtures/family.ged', $this->directory . '/huge.ged');
        copy(__DIR__ . '/fixtures/anna.jpg', $this->directory . '/photos/anna k.jpg');
        if (class_exists(\ZipArchive::class)) {
            $document = Gedcom::convert(Gedcom::read(__DIR__ . '/fixtures/family.ged'), Version::V7_0)->document();
            Gedcom::writeGedzip($document, $this->directory . '/family.gdz', ['photos/anna k.jpg' => __DIR__ . '/fixtures/anna.jpg']);
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    #[Test]
    #[DataProvider('pages')]
    public function examplesRun(string $page): void
    {
        $markdown = (string) file_get_contents(self::ROOT . '/' . $page);
        if (str_contains($markdown, 'readGedzip') && !class_exists(\ZipArchive::class)) {
            self::markTestSkipped('GEDZIP examples need ext-zip');
        }
        preg_match_all('/^(<!-- example: skip -->\n)?```php\n(.*?)^```$/ms', $markdown, $blocks, PREG_SET_ORDER);
        $code = '';
        $uses = [];
        foreach ($blocks as [, $skip, $block]) {
            if ($skip !== '') {
                continue;
            }
            // Imports go first, once each; a page may repeat them from block to block.
            $code .= preg_replace_callback('/^use [^;]+;\n/m', static function (array $m) use (&$uses): string {
                $uses[$m[0]] = true;

                return '';
            }, $block) . "\n";
        }
        if ($code === '') {
            self::markTestSkipped("{$page} has no example to run");
        }
        $prelude = array_filter(explode("\n", self::PRELUDE), static fn(string $line): bool => !isset($uses[$line . "\n"]) && !self::imported($line, array_keys($uses)));
        $script = "<?php\n\ndeclare(strict_types=1);\n\n" . implode('', array_keys($uses)) . implode("\n", $prelude) . "\n\n"
            . 'require ' . var_export(realpath(self::ROOT . '/vendor/autoload.php'), true) . ";\n"
            . "set_error_handler(static function (int \$level, string \$message, string \$file, int \$line): never { throw new \\ErrorException(\$message, 0, \$level, \$file, \$line); });\n\n"
            . $code;
        file_put_contents($this->directory . '/example.php', $script);

        $process = proc_open([PHP_BINARY, '-d', 'zend.assertions=1', 'example.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, "The examples of {$page} failed:\n{$errors}{$output}");
        self::assertSame('', $errors);
    }

    /**
     * Whether a prelude import names a class a page imports itself (`use X\{A, B}` included).
     *
     * @param list<string> $uses
     */
    private static function imported(string $line, array $uses): bool
    {
        if (preg_match('/^use .*\\\\(\w+);$/', $line, $m) !== 1) {
            return false;
        }
        foreach ($uses as $use) {
            if (preg_match('/[\\\\{ ,]' . $m[1] . '[,};]/', $use) === 1) {
                return true;
            }
        }

        return false;
    }
}

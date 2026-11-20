<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The public API (SPEC section 5: everything not marked @internal) is listed in public-api.txt,
 * so that a change of it shows in the diff of a pull request and in UPGRADE.md. After an
 * intended change: KORIN_UPDATE_API=1 vendor/bin/phpunit --filter PublicApiTest.
 */
final class PublicApiTest extends TestCase
{
    private const LIST = __DIR__ . '/public-api.txt';

    #[Test]
    public function thePublicApiIsTheListedOne(): void
    {
        $api = implode("\n", $this->api()) . "\n";
        if (getenv('KORIN_UPDATE_API') === '1') {
            file_put_contents(self::LIST, $api);
        }
        self::assertSame((string) file_get_contents(self::LIST), $api);
    }

    #[Test]
    public function aPublicClassCannotBeExtended(): void
    {
        $open = array_filter($this->classes(), static fn(\ReflectionClass $class): bool => !$class->isFinal() && !$class->isInterface() && !$class->isAbstract());
        self::assertSame([], array_values(array_map(static fn(\ReflectionClass $class): string => $class->getName(), $open)));
    }

    /** @return list<string> */
    private function api(): array
    {
        $lines = [];
        foreach ($this->classes() as $class) {
            $backing = enum_exists($class->getName()) ? (new \ReflectionEnum($class->getName()))->getBackingType() : null;
            $lines[] = $this->kind($class) . ' ' . $class->getName() . ($backing !== null ? ': ' . $backing : '') . $this->parents($class);
            foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() === $class->getName() && !self::isInternal($constant->getDocComment())) {
                    $lines[] = '    ' . ($constant->isEnumCase() ? 'case ' : 'const ') . $constant->getName();
                }
            }
            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class->getName() && !self::isInternal($property->getDocComment()) && !$class->isEnum()) {
                    $lines[] = '    ' . ($property->isReadOnly() ? 'readonly ' : '') . $property->getType() . ' $' . $property->getName();
                }
            }
            $methods = array_filter(
                $class->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn(\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName() && !self::isInternal($method->getDocComment())
                    && (!$class->isEnum() || !in_array($method->getName(), ['cases', 'from', 'tryFrom'], true)),
            );
            usort($methods, static fn(\ReflectionMethod $a, \ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));
            foreach ($methods as $method) {
                $lines[] = '    ' . $this->signature($method);
            }
        }

        return array_map(static fn(string $line): string => str_replace('Korin\\Gedcom\\', '', $line), $lines);
    }

    /** @return list<\ReflectionClass<object>> the classes of src not marked @internal, by name */
    private function classes(): array
    {
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace (\S+);/m', $source, $namespace) === 1
                && preg_match('/^(?:(?:final|abstract|readonly) )*(?:class|interface|enum|trait) (\w+)/m', $source, $name) === 1) {
                /** @var class-string $fqcn */
                $fqcn = $namespace[1] . '\\' . $name[1];
                $class = new \ReflectionClass($fqcn);
                if (!self::isInternal($class->getDocComment())) {
                    $classes[$fqcn] = $class;
                }
            }
        }
        ksort($classes);

        return array_values($classes);
    }

    /** @param \ReflectionClass<object> $class */
    private function kind(\ReflectionClass $class): string
    {
        return match (true) {
            $class->isInterface() => 'interface',
            $class->isEnum() => 'enum',
            $class->isTrait() => 'trait',
            default => ($class->isFinal() ? 'final ' : '') . ($class->isAbstract() ? 'abstract ' : '') . ($class->isReadOnly() ? 'readonly ' : '') . 'class',
        };
    }

    /** @param \ReflectionClass<object> $class */
    private function parents(\ReflectionClass $class): string
    {
        $parent = $class->getParentClass();
        $interfaces = array_filter($class->getInterfaceNames(), static fn(string $name): bool => !in_array($name, ['UnitEnum', 'BackedEnum', 'Stringable', 'Throwable', 'Traversable'], true));
        sort($interfaces);

        return ($parent !== false ? ' extends ' . $parent->getName() : '') . ($interfaces !== [] ? ' implements ' . implode(', ', $interfaces) : '');
    }

    private function signature(\ReflectionMethod $method): string
    {
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = ltrim($this->type($parameter->getType(), $method) . ' ')
                . ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName()
                . ($parameter->isDefaultValueAvailable() ? ' = …' : '');
        }

        return ($method->isStatic() ? 'static ' : '') . $method->getName() . '(' . implode(', ', $parameters) . ')' . ($method->hasReturnType() ? ': ' . $this->type($method->getReturnType(), $method) : '');
    }

    /** A type as written, but self by its class: reflection gives either, by PHP version. */
    private function type(?\ReflectionType $type, \ReflectionMethod $method): string
    {
        return (string) preg_replace('/(?<![\\\\\w])self\b/', $method->getDeclaringClass()->getName(), (string) $type);
    }

    private static function isInternal(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^\s*\*?\s*@internal\b|\/\*\*\s*@internal\b/m', $doc) === 1;
    }
}

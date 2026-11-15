<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit;

use Korin\Gedcom\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    #[Test]
    public function mapsDeclaredVersions(): void
    {
        self::assertSame(Version::V7_0, Version::fromDeclared('7.0.18'));
        self::assertSame(Version::V7_0, Version::fromDeclared('7.0'));
        self::assertSame(Version::V5_5_1, Version::fromDeclared(' 5.5.1 '));
        self::assertSame(Version::V5_5, Version::fromDeclared('5.3'));
        self::assertNull(Version::fromDeclared('7.1'));
        self::assertNull(Version::fromDeclared('6.0'));
    }
}

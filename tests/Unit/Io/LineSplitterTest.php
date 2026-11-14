<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Io;

use Korin\Gedcom\Io\Codec\Codecs;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\Io\LineSplitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineSplitterTest extends TestCase
{
    #[Test]
    public function keepsEveryTerminatorKind(): void
    {
        $lines = iterator_to_array(LineSplitter::split("a\r\nbb\rc\nd", Encoding::Utf8), false);

        self::assertSame([[0, 1, 2], [3, 2, 1], [6, 1, 1], [8, 1, 0]], $lines);
    }

    #[Test]
    public function splitsUtf16OnlyAtCodeUnitBoundaries(): void
    {
        // U+0A0D then U+0D00 contain 0x0A and 0x0D bytes at odd offsets that must not split lines.
        $bytes = Codecs::for(Encoding::Utf16Le)->encode("\u{0A0D}\u{0D00}\r\nx");

        self::assertSame([[0, 4, 4], [8, 2, 0]], iterator_to_array(LineSplitter::split($bytes, Encoding::Utf16Le), false));
    }
}

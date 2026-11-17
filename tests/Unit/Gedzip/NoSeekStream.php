<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Gedzip;

/** A stream wrapper that reads static bytes and cannot seek, as http:// or php://stdin. */
final class NoSeekStream
{
    public static string $bytes = '';

    public static int $opened = 0;

    /** @var resource|null set by PHP */
    public $context;

    private int $position = 0;

    private string $data = '';

    public function stream_open(): bool
    {
        ++self::$opened;
        $this->data = self::$bytes;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

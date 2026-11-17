<?php

declare(strict_types=1);

namespace Korin\Gedcom\Extension;

use Korin\Gedcom\Syntax\Node;

/**
 * The program that wrote a file, from HEAD.SOUR: the system identifier, its VERS and NAME
 * (5.x and 7.0 alike), and the {@see Program} when the identifier is known. The version is
 * HEAD.SOUR.VERS, or the number in the identifier when there is no VERS ("PAF 2.31").
 */
final readonly class SourceSystem
{
    public function __construct(
        public string $identifier,
        public ?string $version = null,
        public ?string $name = null,
    ) {}

    /** The source system of a HEAD; null without HEAD.SOUR. */
    public static function of(Node $head): ?self
    {
        $sour = $head->first('SOUR');
        $identifier = $sour?->value();
        if ($sour === null || $identifier === null || trim($identifier) === '') {
            return null;
        }

        return new self(trim($identifier), self::trimmed($sour->first('VERS')?->value()), self::trimmed($sour->first('NAME')?->value()));
    }

    public function program(): ?Program
    {
        return Program::fromIdentifier($this->identifier);
    }

    /** HEAD.SOUR.VERS, or the version in the identifier ("PAF 5.0"). */
    public function version(): ?string
    {
        return $this->version ?? (preg_match('/ ([0-9]+(?:\.[0-9]+)*)$/', $this->identifier, $m) === 1 ? $m[1] : null);
    }

    /** Whether the version is known and lower than $version, compared number by number. */
    public function isBefore(string $version): bool
    {
        $own = $this->version();
        if ($own === null || preg_match('/^[0-9]+(?:\.[0-9]+)*/', $own, $m) !== 1) {
            return false;
        }

        return version_compare($m[0], $version, '<');
    }

    private static function trimmed(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

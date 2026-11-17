<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;

/**
 * The value of a personal NAME: the text before the surname, the surname between slashes and
 * the text after it ("Анна /Коваль/", "Ivan /Petrenko/ Jr."). The same in every version. The
 * name pieces (GIVN, SURN, NPFX and the others) are substructures, not part of the value.
 */
final readonly class PersonalName implements \Stringable
{
    private const TEXT = '~^[^\x00-\x1F/]*$~u';

    /**
     * @param ?string $surname null when the name has no slashes; "" for "//"
     */
    public function __construct(
        public string $given = '',
        public ?string $surname = null,
        public string $suffix = '',
    ) {
        foreach ([$given, $surname ?? '', $suffix] as $part) {
            if (preg_match(self::TEXT, $part) !== 1) {
                throw new InvalidValueException('A name part has neither slashes nor control characters');
            }
        }
        if ($surname === null && $suffix !== '') {
            throw new InvalidValueException('A name without a surname has no text after it');
        }
    }

    /** "Анна", "Коваль": "Анна /Коваль/". */
    public static function of(string $given, ?string $surname = null, string $suffix = ''): self
    {
        return new self($given, $surname, $suffix);
    }

    /** @throws InvalidValueException when the value has other than none or two slashes, or control characters */
    public static function parse(string $value): self
    {
        return self::tryParse($value) ?? throw new InvalidValueException('Not a personal name: text with at most one /surname/');
    }

    public static function tryParse(string $value): ?self
    {
        if (preg_match('~^([^\x00-\x1F/]*)(?:/([^\x00-\x1F/]*)/([^\x00-\x1F/]*))?$~u', $value, $m) !== 1) {
            return null;
        }

        return isset($m[2]) ? new self(rtrim($m[1]), $m[2], ltrim($m[3] ?? '')) : new self($m[1]);
    }

    public function withGiven(string $given): self
    {
        return new self($given, $this->surname, $this->suffix);
    }

    public function withSurname(?string $surname): self
    {
        return new self($this->given, $surname, $surname === null ? '' : $this->suffix);
    }

    /** The name as it is read: "Анна Коваль", without slashes. */
    public function full(): string
    {
        return implode(' ', array_filter([$this->given, $this->surname ?? '', $this->suffix], static fn(string $part): bool => $part !== ''));
    }

    /** The NAME value: "Анна /Коваль/". */
    public function toGedcom(): string
    {
        if ($this->surname === null) {
            return $this->given;
        }

        return ltrim($this->given . ' /') . $this->surname . rtrim('/ ' . $this->suffix);
    }

    public function __toString(): string
    {
        return $this->toGedcom();
    }
}

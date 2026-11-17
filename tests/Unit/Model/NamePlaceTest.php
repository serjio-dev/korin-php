<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Model\Coordinates;
use Korin\Gedcom\Model\PersonalName;
use Korin\Gedcom\Model\Place;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamePlaceTest extends TestCase
{
    /** @return iterable<string, array{string, PersonalName, string}> */
    public static function names(): iterable
    {
        yield 'given and surname' => ['Анна /Коваль/', PersonalName::of('Анна', 'Коваль'), 'Анна Коваль'];
        yield 'surname only' => ['/Коваль/', PersonalName::of('', 'Коваль'), 'Коваль'];
        yield 'suffix' => ['Ivan /Petrenko/ Jr.', PersonalName::of('Ivan', 'Petrenko', 'Jr.'), 'Ivan Petrenko Jr.'];
        yield 'no surname' => ['Анна', PersonalName::of('Анна'), 'Анна'];
        yield 'empty surname' => ['Анна //', PersonalName::of('Анна', ''), 'Анна'];
    }

    #[Test]
    #[DataProvider('names')]
    public function namesAreParsedAndWritten(string $value, PersonalName $expected, string $full): void
    {
        $name = PersonalName::parse($value);

        self::assertEquals($expected, $name);
        self::assertSame($value, $name->toGedcom());
        self::assertSame($full, $name->full());
    }

    #[Test]
    public function nameIsEditedByParts(): void
    {
        $name = PersonalName::parse('Анна /Коваль/');

        self::assertSame('Анна /Ковальова/', (string) $name->withSurname('Ковальова'));
        self::assertSame('Ганна /Коваль/', (string) $name->withGiven('Ганна'));
        self::assertSame('Анна', (string) $name->withSurname(null));
    }

    #[Test]
    public function nameWithOneSlashIsNotParsed(): void
    {
        self::assertNull(PersonalName::tryParse('Анна /Коваль'));
        self::assertNull(PersonalName::tryParse("Анна\t/Коваль/"));
        $this->expectException(InvalidValueException::class);
        PersonalName::of('Анна', 'Ко/валь');
    }

    #[Test]
    public function placeHasItsParts(): void
    {
        $place = Place::of('Полтава, , Україна');

        self::assertSame(['Полтава', '', 'Україна'], $place->parts);
        self::assertSame('Полтава', $place->name());
        self::assertSame('Полтава, , Україна', $place->toGedcom());
        self::assertSame('Kyiv, Ukraine', (string) Place::of('Kyiv,Ukraine'));
        self::assertSame('Україна', Place::of(', Україна')->name());
        $this->expectException(InvalidValueException::class);
        new Place(['Полтава, Україна']);
    }

    /** @return iterable<string, array{string, string, float, float, string, string}> */
    public static function coordinates(): iterable
    {
        yield 'north east' => ['N50.45', 'E30.52', 50.45, 30.52, 'N50.45', 'E30.52'];
        yield 'south west' => ['S33.9', 'W18.4', -33.9, -18.4, 'S33.9', 'W18.4'];
        yield 'integers, lowercase' => ['n0', 'e180', 0.0, 180.0, 'N0', 'E180'];
        yield 'small' => ['N0.00001', 'W0.5', 0.00001, -0.5, 'N0.00001', 'W0.5'];
    }

    #[Test]
    #[DataProvider('coordinates')]
    public function coordinatesAreParsedAndWritten(string $lati, string $long, float $latitude, float $longitude, string $lati7, string $long7): void
    {
        $map = Coordinates::parse($lati, $long);

        self::assertSame([$latitude, $longitude], [$map->latitude, $map->longitude]);
        self::assertSame([$lati7, $long7], [$map->latitudeToGedcom(), $map->longitudeToGedcom()]);
    }

    #[Test]
    public function coordinatesOutOfRangeAreNotParsed(): void
    {
        self::assertNull(Coordinates::tryParse('N90.5', 'E0'));
        self::assertNull(Coordinates::tryParse('50.45', 'E30'));
        $this->expectException(InvalidValueException::class);
        new Coordinates(0, 181);
    }
}

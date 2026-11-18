<?php

declare(strict_types=1);

/*
 * The value grammars: dates, ages, coordinates, names. The first byte picks the version, the
 * rest is the value. A value that parses must write back to a value that parses to the same.
 *
 * vendor/bin/php-fuzzer fuzz fuzz/values.php build/fuzz/values
 */

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Model\Age;
use Korin\Gedcom\Model\Coordinates;
use Korin\Gedcom\Model\GedcomDate;
use Korin\Gedcom\Model\PersonalName;
use Korin\Gedcom\Model\Place;
use Korin\Gedcom\Version;
use PhpFuzzer\Config;

require __DIR__ . '/../vendor/autoload.php';

/** @var Config $config */
$config->setMaxLen(256);
$config->addDictionary(__DIR__ . '/gedcom.dict');
$config->setAllowedExceptions([]);
$config->setTarget(static function (string $input): void {
    $versions = [Version::V5_5, Version::V5_5_1, Version::V5_5_5, Version::V7_0];
    $version = $versions[ord($input[0] ?? "\0") % 4];
    $value = substr($input, 1);

    $date = GedcomDate::tryParse($value, $version);
    if ($date !== null) {
        $days = $date->days();
        if ($days !== null && $days[0] > $days[1]) {
            throw new LogicException('A date ends before it begins');
        }
        $written = $date->toGedcom($version);
        if (GedcomDate::tryParse($written, $version)?->toGedcom($version) !== $written) {
            throw new LogicException('A date does not read back as written');
        }
    }
    try {
        $age = Age::parse($value, $version);
        if (Age::parse($age->toGedcom($version), $version)->toGedcom($version) !== $age->toGedcom($version)) {
            throw new LogicException('An age does not read back as written');
        }
    } catch (InvalidValueException) {
    }
    [$latitude, $longitude] = explode(' ', $value, 2) + ['', ''];
    try {
        Coordinates::parse($latitude, $longitude, $version);
    } catch (InvalidValueException) {
    }
    try {
        $name = PersonalName::parse($value);
        if (PersonalName::parse($name->toGedcom())->toGedcom() !== $name->toGedcom()) {
            throw new LogicException('A name does not read back as written');
        }
    } catch (InvalidValueException) {
    }
    try {
        $place = Place::of($value);
        if (Place::of($place->toGedcom())->toGedcom() !== $place->toGedcom()) {
            throw new LogicException('A place does not read back as written');
        }
    } catch (InvalidValueException) {
    }
});

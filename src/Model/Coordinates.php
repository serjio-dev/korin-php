<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;
use Korin\Gedcom\Version;

/** The MAP of a place: LATI and LONG as degrees, north and east positive ("N50.45", "E30.52"). */
final readonly class Coordinates
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if (abs($latitude) > 90 || abs($longitude) > 180 || is_nan($latitude) || is_nan($longitude)) {
            throw new InvalidValueException('Coordinates are a latitude of at most 90 and a longitude of at most 180 degrees');
        }
    }

    /** @throws InvalidValueException when LATI or LONG is not one of the version's grammar */
    public static function parse(string $latitude, string $longitude, Version $version = Version::V7_0): self
    {
        return self::tryParse($latitude, $longitude, $version) ?? throw new InvalidValueException("Not GEDCOM {$version->value} coordinates: \"N50.45\" and \"E30.52\"");
    }

    /** 5.x hemispheres may be in either case. */
    public static function tryParse(string $latitude, string $longitude, Version $version = Version::V7_0): ?self
    {
        if (!Grammars::isLatitude($version, $latitude) || !Grammars::isLongitude($version, $longitude)) {
            return null;
        }
        $north = (float) substr($latitude, 1);
        $east = (float) substr($longitude, 1);
        if ($north > 90 || $east > 180) {
            // "N90.5": the grammars allow decimals after the largest degree.
            return null;
        }

        return new self(strtoupper($latitude[0]) === 'S' ? -$north : $north, strtoupper($longitude[0]) === 'W' ? -$east : $east);
    }

    public function latitudeToGedcom(): string
    {
        return ($this->latitude < 0 ? 'S' : 'N') . $this->degrees($this->latitude);
    }

    public function longitudeToGedcom(): string
    {
        return ($this->longitude < 0 ? 'W' : 'E') . $this->degrees($this->longitude);
    }

    /** The shortest decimal that reads back as the same number (whatever the precision ini), without an exponent. */
    private function degrees(float $degrees): string
    {
        $text = var_export(abs($degrees), true);
        if (preg_match('/^([0-9])(?:\.([0-9]+))?E([-+][0-9]+)$/', $text, $m) === 1) {
            // Only small numbers have an exponent here: 1.0E-5 is 0.00001.
            $digits = rtrim($m[1] . $m[2], '0');
            $text = '0.' . str_repeat('0', -(int) $m[3] - 1) . $digits;
        }

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}

<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;

/** The MAP of a place: LATI and LONG as degrees, north and east positive ("N50.45", "E30.52"). */
final readonly class Coordinates
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if (abs($latitude) > 90 || abs($longitude) > 180 || is_nan($latitude) || is_nan($longitude)) {
            throw new InvalidValueException('Coordinates are a latitude of at most 90 and a longitude of at most 180 degrees');
        }
    }

    /** @throws InvalidValueException when LATI or LONG is not one of the GEDCOM grammar */
    public static function parse(string $latitude, string $longitude): self
    {
        return self::tryParse($latitude, $longitude) ?? throw new InvalidValueException('Not coordinates: "N50.45" and "E30.52"');
    }

    /** As the 5.x grammar, hemispheres in either case. */
    public static function tryParse(string $latitude, string $longitude): ?self
    {
        if (preg_match('/^([NS])((?:90|[0-8]?[0-9])(?:\.[0-9]+)?)$/i', $latitude, $lat) !== 1
            || preg_match('/^([EW])((?:180|1[0-7][0-9]|0?[0-9]?[0-9])(?:\.[0-9]+)?)$/i', $longitude, $long) !== 1
            || (float) $lat[2] > 90 || (float) $long[2] > 180) {
            return null;
        }

        return new self((strtoupper($lat[1]) === 'S' ? -1 : 1) * (float) $lat[2], (strtoupper($long[1]) === 'W' ? -1 : 1) * (float) $long[2]);
    }

    public function latitudeToGedcom(): string
    {
        return ($this->latitude < 0 ? 'S' : 'N') . $this->degrees($this->latitude);
    }

    public function longitudeToGedcom(): string
    {
        return ($this->longitude < 0 ? 'W' : 'E') . $this->degrees($this->longitude);
    }

    /** The shortest decimal that reads back as the same number, without an exponent. */
    private function degrees(float $degrees): string
    {
        $text = (string) abs($degrees);

        return str_contains($text, 'E') ? rtrim(rtrim(sprintf('%.12F', abs($degrees)), '0'), '.') : $text;
    }
}

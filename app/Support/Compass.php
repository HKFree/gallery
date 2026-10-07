<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Compass directions and great-circle geometry for view descriptions.
 */
class Compass
{
    /** Czech 8-point compass abbreviations, clockwise from north. */
    private const POINTS = ['S', 'SV', 'V', 'JV', 'J', 'JZ', 'Z', 'SZ'];

    /** Direction words (ASCII, lowercase) and their headings. */
    private const WORDS = [
        'sever' => 0, 'severovychod' => 45, 'vychod' => 90, 'jihovychod' => 135,
        'jih' => 180, 'jihozapad' => 225, 'zapad' => 270, 'severozapad' => 315,
        // Adjective forms used in file names ("severni pohled").
        'severni' => 0, 'vychodni' => 90, 'jizni' => 180, 'zapadni' => 270,
    ];

    /** Uppercase abbreviations accepted in file names (single letters are too ambiguous). */
    private const ABBREVIATIONS = ['SV' => 45, 'JV' => 135, 'JZ' => 225, 'SZ' => 315];

    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * The view direction a file name states, in degrees, or null. Understands an explicit
     * azimuth ("128°") and Czech direction words with or without diacritics, including split
     * compounds ("severo východ", "sever východ"), e.g. "Sever - směr Plačice.jpg", "sektor_jih_2019.jpg".
     */
    public static function headingFromFilename(string $filename): ?int
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        if (preg_match('/(?<!\d)(\d{1,3})\s*°/u', $name, $matches) === 1 && (int) $matches[1] < 360) {
            return (int) $matches[1];
        }

        $tokens = preg_split('/[^a-z]+/', Str::lower(Str::ascii($name)), flags: PREG_SPLIT_NO_EMPTY);

        foreach ($tokens as $index => $token) {
            // Split compounds: "severo východ", but also "sever východ" and "jih západ".
            $stem = ['severo' => 'severo', 'sever' => 'severo', 'jiho' => 'jiho', 'jih' => 'jiho'][$token] ?? null;
            $compound = $stem !== null && in_array($tokens[$index + 1] ?? '', ['vychod', 'zapad'], true) ? $stem.$tokens[$index + 1] : null;

            if ($compound !== null && isset(self::WORDS[$compound])) {
                return self::WORDS[$compound];
            }

            if (isset(self::WORDS[$token])) {
                return self::WORDS[$token];
            }
        }

        foreach (preg_split('/[^A-Za-z]+/', Str::ascii($name), flags: PREG_SPLIT_NO_EMPTY) as $token) {
            if (isset(self::ABBREVIATIONS[$token])) {
                return self::ABBREVIATIONS[$token];
            }
        }

        return null;
    }

    /**
     * The 8-point Czech abbreviation of a heading, e.g. 128 → "JV".
     */
    public static function point(int $heading): string
    {
        return self::POINTS[(int) round(($heading % 360) / 45) % 8];
    }

    /**
     * Initial great-circle bearing from one point to another, in degrees 0–360.
     */
    public static function bearing(float $fromLat, float $fromLon, float $toLat, float $toLon): float
    {
        [$lat1, $lat2, $deltaLon] = [deg2rad($fromLat), deg2rad($toLat), deg2rad($toLon - $fromLon)];

        $y = sin($deltaLon) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLon);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Great-circle (haversine) distance in kilometres.
     */
    public static function distanceKm(float $fromLat, float $fromLon, float $toLat, float $toLon): float
    {
        $a = sin(deg2rad($toLat - $fromLat) / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin(deg2rad($toLon - $fromLon) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1, sqrt($a)));
    }

    /**
     * Smallest angle between two headings, 0–180.
     */
    public static function angleBetween(float $a, float $b): float
    {
        $difference = fmod(abs($a - $b), 360);

        return $difference > 180 ? 360 - $difference : $difference;
    }
}

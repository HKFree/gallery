<?php

namespace App\Support;

/**
 * A small static map of where a photo looks: map tiles around the origin (Web Mercator), with
 * the view cone and the APs within it drawn on top. Pure geometry, rendered by
 * `x-gallery.mini-map`; no map library is needed.
 */
class MiniMap
{
    /** Zoom level: at ~50° N one pixel is about 6 m, so the map spans about 1.2 km. */
    public const ZOOM = 14;

    public const WIDTH = 200;

    public const HEIGHT = 200;

    private const TILE = 256;

    /** Half the opening of the drawn view cone, in degrees (as used for "APs in view"). */
    private const HALF_ANGLE = 25;

    /**
     * @param  list<array{name: string, lat: float, lon: float}>  $targets  APs in view
     * @return array{tiles: list<array{url: string, left: int, top: int}>, cone: string, center: array{x: float, y: float}, targets: list<array{name: string, x: float, y: float, inside: bool}>, heading: int}
     */
    public static function make(float $lat, float $lon, int $heading, array $targets, string $tileUrl): array
    {
        [$cx, $cy] = self::pixel($lat, $lon);
        $left = $cx - self::WIDTH / 2;
        $top = $cy - self::HEIGHT / 2;
        $tiles = [];

        for ($ty = (int) floor($top / self::TILE); $ty <= (int) floor(($top + self::HEIGHT) / self::TILE); $ty++) {
            for ($tx = (int) floor($left / self::TILE); $tx <= (int) floor(($left + self::WIDTH) / self::TILE); $tx++) {
                $tiles[] = [
                    'url' => str_replace(['{z}', '{x}', '{y}'], [self::ZOOM, $tx, $ty], $tileUrl),
                    'left' => (int) round($tx * self::TILE - $left),
                    'top' => (int) round($ty * self::TILE - $top),
                ];
            }
        }

        $center = ['x' => self::WIDTH / 2, 'y' => self::HEIGHT / 2];
        $reach = self::WIDTH * 0.7;
        $cone = [$center];

        foreach (range($heading - self::HALF_ANGLE, $heading + self::HALF_ANGLE, 5) as $degrees) {
            $cone[] = [
                'x' => $center['x'] + $reach * sin(deg2rad($degrees)),
                'y' => $center['y'] - $reach * cos(deg2rad($degrees)),
            ];
        }

        return [
            'tiles' => $tiles,
            'cone' => implode(' ', array_map(fn (array $p): string => round($p['x'], 1).','.round($p['y'], 1), $cone)),
            'center' => $center,
            'targets' => array_map(function (array $target) use ($left, $top, $center): array {
                [$x, $y] = self::pixel($target['lat'], $target['lon']);
                [$dx, $dy] = [$x - $left - $center['x'], $y - $top - $center['y']];
                $margin = 12;
                $fit = min(
                    $dx === 0.0 ? INF : ($center['x'] - $margin) / abs($dx),
                    $dy === 0.0 ? INF : ($center['y'] - $margin) / abs($dy),
                );
                // APs beyond the map are drawn at its edge, in their true direction.
                $inside = $fit >= 1;
                $scale = $inside ? 1 : $fit;

                return [
                    'name' => $target['name'],
                    'x' => round($center['x'] + $dx * $scale, 1),
                    'y' => round($center['y'] + $dy * $scale, 1),
                    'inside' => $inside,
                ];
            }, $targets),
            'heading' => $heading,
        ];
    }

    /**
     * Web Mercator pixel coordinates of a point at {@see self::ZOOM}.
     *
     * @return array{0: float, 1: float}
     */
    private static function pixel(float $lat, float $lon): array
    {
        $scale = self::TILE * 2 ** self::ZOOM;
        $latRad = deg2rad($lat);

        return [
            ($lon + 180) / 360 * $scale,
            (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $scale,
        ];
    }
}

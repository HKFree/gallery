<?php

use App\Support\MiniMap;

const TILES = 'https://tiles.example/{z}/{x}/{y}.png';

it('covers the map with the tiles around the origin', function () {
    $map = MiniMap::make(50.2092, 15.8328, 0, [], TILES);

    expect($map['tiles'])->not->toBeEmpty()->toHaveCount(count($map['tiles']))
        ->and($map['tiles'][0]['url'])->toStartWith('https://tiles.example/14/');

    // Every map pixel lies on some tile.
    foreach ([[0, 0], [199, 0], [0, 199], [199, 199], [100, 100]] as [$x, $y]) {
        $covered = collect($map['tiles'])->contains(fn ($t) => $x >= $t['left'] && $x < $t['left'] + 256 && $y >= $t['top'] && $y < $t['top'] + 256);
        expect($covered)->toBeTrue();
    }
});

it('points the view cone in the heading', function (int $heading, string $direction) {
    $map = MiniMap::make(50.2, 15.8, $heading, [], TILES);
    $points = array_map(fn ($p) => array_map('floatval', explode(',', $p)), explode(' ', $map['cone']));
    $tip = $points[(int) floor(count($points) / 2)];

    expect(match ($direction) {
        'up' => $tip[1] < 50 && abs($tip[0] - 100) < 5,
        'right' => $tip[0] > 150 && abs($tip[1] - 100) < 5,
        'down' => $tip[1] > 150,
    })->toBeTrue();
})->with([[0, 'up'], [90, 'right'], [180, 'down']]);

it('places APs in view on the map, and far ones at its edge in their direction', function () {
    $map = MiniMap::make(50.2092, 15.8328, 45, [
        ['name' => 'Blízko', 'lat' => 50.2120, 'lon' => 15.8360],
        ['name' => 'Daleko', 'lat' => 50.2190, 'lon' => 15.8650],
    ], TILES);

    [$near, $far] = $map['targets'];

    expect($near['inside'])->toBeTrue()
        ->and($near['x'])->toBeGreaterThan(100)->and($near['y'])->toBeLessThan(100)
        ->and($far['inside'])->toBeFalse()
        ->and(max(abs($far['x'] - 100), abs($far['y'] - 100)))->toEqualWithDelta(88, 0.5)
        ->and($far['x'])->toBeGreaterThan(100)->and($far['y'])->toBeLessThan(100);
});

<?php

use App\Support\Compass;

it('reads the view direction from file names', function (string $filename, ?int $heading) {
    expect(Compass::headingFromFilename($filename))->toBe($heading);
})->with([
    'azimuth' => ['128° jih Slatiny a směr AP Kladská.JPG', 128],
    'word' => ['Sever - směr Plačice.jpg', 0],
    'word in snake case' => ['sektor_jih_2019_small.jpg', 180],
    'split compound' => ['Pohled směr severo východ.jpg', 45],
    'split compound without -o' => ['Pohled směr sever východ 2.jpg', 45],
    'split south-west' => ['jih západ.jpg', 225],
    'plain direction before an unrelated word' => ['jih stožár.jpg', 180],
    'compound with diacritics' => ['jihozápad.jpg', 225],
    'without diacritics' => ['7-zapad-2018.jpg', 270],
    'adjective' => ['severni pohled.jpg', 0],
    'abbreviation' => ['vyhled_JV.jpg', 135],
    'number without degree sign' => ['DSC_0128.jpg', null],
    'town name containing a direction' => ['Jihlava.jpg', null],
    'genitive (location, not direction)' => ['od jizniho stozaru.jpg', null],
    'lowercase two letters' => ['sv_vaclav.jpg', null],
    'azimuth out of range' => ['400°.jpg', null],
]);

it('names headings with Czech compass points', function () {
    expect(array_map(Compass::point(...), [0, 44, 45, 128, 180, 225, 270, 337, 359]))
        ->toBe(['S', 'SV', 'SV', 'JV', 'J', 'JZ', 'Z', 'SZ', 'S']);
});

it('computes bearings and distances', function () {
    // 0.02° of latitude due north is about 2.2 km.
    expect(Compass::bearing(49.195, 16.61, 49.215, 16.61))->toEqualWithDelta(0.0, 0.01)
        ->and(Compass::distanceKm(49.195, 16.61, 49.215, 16.61))->toEqualWithDelta(2.224, 0.01)
        ->and(Compass::bearing(49.195, 16.61, 49.195, 16.70))->toEqualWithDelta(90.0, 0.1)
        ->and(Compass::angleBetween(350, 10))->toBe(20.0)
        ->and(Compass::angleBetween(10, 350))->toBe(20.0);
});

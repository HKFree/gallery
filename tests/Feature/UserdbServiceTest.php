<?php

use App\Services\UserdbService;
use Illuminate\Support\Facades\Http;

it('caches the areas response for five minutes', function () {
    fakeUserdbAreas();
    $service = app(UserdbService::class);

    $service->areas();
    $service->areas();

    Http::assertSentCount(1);
});

it('collapses a single same-named AP and groups multi-AP areas', function () {
    fakeUserdbAreas();

    $tree = app(UserdbService::class)->homeTree();
    $slatina = $tree->firstWhere('id', 12);
    $brno = $tree->firstWhere('id', 13);

    expect($slatina['collapsed'])->toBeTrue()
        ->and($slatina['link_ap']['id'])->toBe(101)
        ->and($brno['collapsed'])->toBeFalse()
        ->and($brno['aps'])->toHaveCount(2);
});

it('resolves known APs and rejects unknown ones', function () {
    fakeUserdbAreas();
    $service = app(UserdbService::class);

    expect($service->findAp(13, 202)['name'])->toBe('Brno-Sever')
        ->and($service->findAp(13, 202)['area']['name'])->toBe('Brno')
        ->and($service->findAp(13, 999))->toBeNull()
        ->and($service->findArea(999))->toBeNull();
});

it('reads AP coordinates and ignores missing or malformed ones', function () {
    fakeUserdbAreas([
        '1' => ['id' => 1, 'jmeno' => 'Oblast', 'aps' => [
            '10' => ['id' => 10, 'jmeno' => 'A', 'gps' => '50.22795,15.834133'],
            '11' => ['id' => 11, 'jmeno' => 'B', 'gps' => ' 50.2;15.8 '],
            '12' => ['id' => 12, 'jmeno' => 'C', 'gps' => null],
            '13' => ['id' => 13, 'jmeno' => 'D', 'gps' => '50.2 N, 15.8 E'],
            '14' => ['id' => 14, 'jmeno' => 'E', 'gps' => '0,0'],
            '15' => ['id' => 15, 'jmeno' => 'F', 'gps' => '150.5,15.8'],
            '16' => ['id' => 16, 'jmeno' => 'G'],
        ]],
    ]);

    $aps = app(UserdbService::class)->aps();

    expect($aps[10])->toMatchArray(['lat' => 50.22795, 'lon' => 15.834133, 'area' => ['id' => 1, 'name' => 'Oblast']])
        ->and($aps[11])->toMatchArray(['lat' => 50.2, 'lon' => 15.8])
        ->and($aps->only([12, 13, 14, 15, 16])->map(fn (array $ap) => [$ap['lat'], $ap['lon']])->unique()->values()->all())->toBe([[null, null]]);
});

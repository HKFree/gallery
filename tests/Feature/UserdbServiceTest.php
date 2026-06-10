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

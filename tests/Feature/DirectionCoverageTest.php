<?php

use App\Models\GalleryImage;
use App\Models\GalleryImageDescription;
use App\Models\User;
use App\Services\DirectionCoverage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

/**
 * An indexed photo with a known direction, taken at the given date.
 */
function viewPhoto(int $heading, string $takenAt, int $apId = 201, int $areaId = 13, string $visibility = 'pub'): void
{
    $filename = fake()->unique()->lexify('view-????').'.jpg';
    Storage::disk('local')->put("gallery/ap/{$areaId}/{$apId}/{$visibility}/{$filename}", 'x');
    GalleryImage::factory()->create(['area_id' => $areaId, 'ap_id' => $apId, 'visibility' => $visibility, 'filename' => $filename, 'taken_at' => $takenAt]);
    GalleryImageDescription::factory()->create(['area_id' => $areaId, 'ap_id' => $apId, 'visibility' => $visibility, 'filename' => $filename, 'heading' => $heading]);
}

function sectorStates(array $sectors): array
{
    return collect($sectors)->mapWithKeys(fn (array $sector) => [$sector['point'] => $sector['state']])->all();
}

it('tells fresh, outdated and missing directions apart', function () {
    viewPhoto(0, now()->subYear()->toDateTimeString());
    viewPhoto(10, now()->subYears(8)->toDateTimeString());
    viewPhoto(95, now()->subYears(8)->toDateTimeString());
    viewPhoto(200, now()->subMonth()->toDateTimeString());

    $sectors = app(DirectionCoverage::class)->forGallery('pub', 13, 201);

    expect(sectorStates($sectors))->toBe([
        'S' => 'fresh', 'SV' => 'missing', 'V' => 'stale', 'JV' => 'missing',
        'J' => 'fresh', 'JZ' => 'missing', 'Z' => 'missing', 'SZ' => 'missing',
    ])->and($sectors[0]['count'])->toBe(2);
});

it('names the neighbouring APs that lie in each direction', function () {
    $sectors = collect(app(DirectionCoverage::class)->forGallery('pub', 13, 201))->keyBy('point');

    // Brno-Sever is 2.2 km north of Brno; Slatina 6.9 km to the east-south-east.
    expect($sectors['S']['neighbours'])->toBe(['AP Brno-Sever (2,2 km)'])
        ->and($sectors['V']['neighbours'])->toBe(['AP Slatina (6,9 km)'])
        ->and($sectors['JZ']['neighbours'])->toBe([]);
});

it('counts only photos still in the gallery and only of that gallery', function () {
    GalleryImageDescription::factory()->create(['filename' => 'gone.jpg', 'heading' => 0]);
    viewPhoto(90, now()->toDateTimeString(), visibility: 'priv');
    viewPhoto(180, now()->toDateTimeString(), apId: 202);

    expect(collect(app(DirectionCoverage::class)->forGallery('pub', 13, 201))->sum('count'))->toBe(0);
});

it('shows the coverage on the gallery and timeline, to the public only once there is something', function () {
    $gallery = route('gallery.public', ['area' => 13, 'ap' => 201]);
    $timeline = route('gallery.public.timeline', ['area' => 13, 'ap' => 201]);

    $this->get($gallery)->assertDontSee('Výhledy podle směrů');
    $this->actingAs(User::factory()->admin()->create())->get($gallery)->assertSee('Výhledy podle směrů');

    viewPhoto(0, now()->toDateTimeString());
    auth()->logout();

    $this->get($gallery)->assertSee('Výhledy podle směrů')->assertSee('Chybí:')->assertSee('V (směrem AP Slatina (6,9 km))');
    $this->get($timeline)->assertSee('Výhledy podle směrů');
});

it('lists every AP for managers, fewest covered first', function () {
    viewPhoto(0, now()->toDateTimeString());
    viewPhoto(90, now()->toDateTimeString());
    viewPhoto(0, now()->toDateTimeString(), apId: 202);

    $this->get(route('coverage'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('coverage'))->assertForbidden();

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('coverage'))
        ->assertSuccessful()
        ->assertSeeInOrder(['Slatina', 'Brno-Sever', '1 / 8', 'Brno', '2 / 8']);

    expect(substr_count($response->getContent(), 'data-coverage-row'))->toBe(3);
});

it('is linked from the header for managers only', function () {
    $this->get(route('home'))->assertDontSee(route('coverage'));
    $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertSee(route('coverage'));
});

it('does not show the coverage box on further timeline pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201, 'cursor' => 'eyJzb3J0X2F0IjoiMjAyNC0wMS0wMSAwMDowMDowMCIsImlkIjoxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9']))
        ->assertSuccessful()
        ->assertDontSee('Výhledy podle směrů')
        ->assertDontSee('Fotky jsou ze všech osmi směrů');
});

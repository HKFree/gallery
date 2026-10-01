<?php

use App\Enums\Scene;
use App\Models\GalleryImage;
use App\Models\GalleryImageDescription;
use App\Models\User;
use App\Services\GalleryDescriptions;
use App\Services\GalleryIndex;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

function describeFacts(): ?string
{
    return app(GalleryDescriptions::class)->texts('pub', 13, 201, ['a.jpg'])['a.jpg'] ?? null;
}

function factsFor(array $attributes): void
{
    GalleryImageDescription::factory()->create(['filename' => 'a.jpg', ...$attributes]);
}

it('names the APs in view from the AP, nearest first', function () {
    // Brno-Sever lies 2.2 km due north of Brno.
    factsFor(['heading' => 0, 'heading_source' => 'manual']);

    expect(describeFacts())->toBe('Výhled z AP Brno na S (0°) — směrem AP Brno-Sever (2,2 km).');
});

it('leaves out APs outside the view cone or too far', function () {
    // Slatina is east-south-east of Brno; looking south-west sees nothing.
    factsFor(['heading' => 225, 'heading_source' => 'manual']);

    expect(describeFacts())->toBe('Výhled z AP Brno na JZ (225°).');
});

it('finds APs across north (the 0°/360° wrap)', function () {
    factsFor(['heading' => 350, 'heading_source' => 'manual']);

    expect(describeFacts())->toContain('směrem AP Brno-Sever');
});

it('describes a photo taken elsewhere from its own GPS position', function () {
    // Taken 1.1 km south of Brno, looking north: Brno and Brno-Sever are both in view.
    factsFor(['heading' => 0, 'heading_source' => 'exif', 'origin_lat' => 49.185, 'origin_lon' => 16.61]);

    expect(describeFacts())->toBe('Výhled na S (0°) — směrem AP Brno (1,1 km), AP Brno-Sever (3,3 km).');
});

it('adds a confident scene type and stays silent without facts', function () {
    factsFor(['scene' => Scene::Zastavba, 'scene_score' => 0.9]);
    expect(describeFacts())->toBe('Výhled na zástavbu (rozpoznáno automaticky).');

    GalleryImageDescription::query()->update(['scene_score' => 0.4]);
    expect(describeFacts())->toBeNull();

    GalleryImageDescription::query()->update(['heading' => 270, 'scene_score' => 0.95]);
    expect(describeFacts())->toBe('Výhled z AP Brno na Z (270°). Výhled na zástavbu (rozpoznáno automaticky).');
});

it('keeps "AP" in names that already contain it', function () {
    $ap = ['id' => 1, 'name' => 'Plotiště AP', 'lat' => 50.24, 'lon' => 15.79];
    $facts = GalleryImageDescription::factory()->make(['heading' => 90]);

    expect(app(GalleryDescriptions::class)->compose($facts, $ap, collect([1 => $ap])))->toBe('Výhled z Plotiště AP na V (90°).');
});

it('names the east-south-east AP when looking east', function () {
    factsFor(['heading' => 90, 'heading_source' => 'manual']);

    expect(describeFacts())->toBe('Výhled z AP Brno na V (90°) — směrem AP Slatina (6,9 km).');
});

it('captures the EXIF GPS position and compass heading when indexing', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/phone.jpg', jpegWithGps(49.185, 16.61, 2.5));

    app(GalleryIndex::class)->record('pub', 13, 201, 'phone.jpg');

    expect(GalleryImageDescription::sole())
        ->origin_lat->toEqualWithDelta(49.185, 0.00001)
        ->origin_lon->toEqualWithDelta(16.61, 0.00001)
        ->heading->toBe(3)
        ->heading_source->toBe('exif');
});

it('captures a direction from the file name, and nothing for plain photos', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/Sever - směr Plačice.jpg', 'x');
    Storage::disk('local')->put('gallery/ap/13/201/pub/DSC_0077.jpg', 'x');
    Storage::disk('local')->put('gallery/ap/13/201/pub/gps-only.jpg', jpegWithGps(49.185, 16.61));

    app(GalleryIndex::class)->reconcileAp('pub', 13, 201);

    expect(GalleryImageDescription::orderBy('filename')->get()->map->only(['filename', 'heading', 'heading_source'])->all())->toBe([
        ['filename' => 'Sever - směr Plačice.jpg', 'heading' => 0, 'heading_source' => 'filename'],
        ['filename' => 'gps-only.jpg', 'heading' => null, 'heading_source' => null],
    ]);
});

it('does not overwrite a direction set by a manager when re-indexing', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/sever.jpg', 'x');
    factsFor(['filename' => 'sever.jpg', 'heading' => 200, 'heading_source' => 'manual']);

    app(GalleryIndex::class)->record('pub', 13, 201, 'sever.jpg');

    expect(GalleryImageDescription::sole()->heading)->toBe(200);
});

it('forgets the facts of a trashed photo', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'x');
    factsFor(['heading' => 0]);

    $this->actingAs(User::factory()->admin()->create())
        ->deleteJson(route('gallery.destroy', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'filename' => 'a.jpg']))
        ->assertOk();

    expect(GalleryImageDescription::count())->toBe(0);
});

it('shows descriptions on grid and timeline tiles, as alt text too', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'x');
    GalleryImage::factory()->create(['filename' => 'a.jpg']);
    factsFor(['heading' => 0, 'heading_source' => 'manual']);
    $text = 'Výhled z AP Brno na S (0°) — směrem AP Brno-Sever (2,2 km).';

    foreach (['gallery.public', 'gallery.public.timeline', 'timeline'] as $route) {
        $parameters = $route === 'timeline' ? [] : ['area' => 13, 'ap' => 201];

        $this->get(route($route, $parameters))
            ->assertSee('alt="'.e($text).'"', escape: false)
            ->assertSee('data-description', escape: false);
    }
});

<?php

use App\Enums\Scene;
use App\Models\ConfluenceImport;
use App\Models\ConfluenceImportItem;
use App\Models\GalleryImageDescription;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'x');
    Storage::disk('local')->put('gallery/ap/13/201/pub/b.jpg', 'x');
});

function descriptionUrl(): string
{
    return route('gallery.description', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]);
}

it('sets a direction by hand and returns the new description', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'heading' => 0])
        ->assertOk()
        ->assertJson(['description' => 'Výhled z AP Brno na S (0°) — směrem AP Brno-Sever (2,2 km).']);

    expect(GalleryImageDescription::sole())
        ->heading->toBe(0)
        ->heading_source->toBe('manual')
        ->edited_by->toBe($admin->id);
});

it('clears a direction with an empty value, also without JavaScript', function () {
    GalleryImageDescription::factory()->create(['filename' => 'a.jpg', 'heading' => 90, 'heading_source' => 'filename']);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->post(descriptionUrl(), ['filename' => 'a.jpg', 'heading' => ''])
        ->assertRedirect(route('gallery.public', ['area' => 13, 'ap' => 201]));

    expect(GalleryImageDescription::sole())->heading->toBeNull()->heading_source->toBeNull();
});

it('stores a recognised scene type', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'scene' => 'sidliste', 'score' => 0.91])
        ->assertOk()
        ->assertJson(['description' => 'Výhled na sídliště (rozpoznáno automaticky).']);

    expect(GalleryImageDescription::sole())->scene->toBe(Scene::Sidliste)->scene_score->toBe(0.91);
});

it('validates what the browser sends', function (array $data, string $field) {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson(descriptionUrl(), ['filename' => 'a.jpg', ...$data])
        ->assertJsonValidationErrors($field);
})->with([
    'nothing to set' => [[], 'heading'],
    'heading out of range' => [['heading' => 360], 'heading'],
    'unknown scene' => [['scene' => 'castle', 'score' => 0.9], 'scene'],
    'scene without score' => [['scene' => 'les'], 'score'],
    'score out of range' => [['scene' => 'les', 'score' => 1.5], 'score'],
]);

it('only accepts existing, untrashed photos of the gallery', function (string $filename) {
    Storage::disk('local')->put('gallery/ap/13/201/pub/_trashed_x_c.jpg', 'x');

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(descriptionUrl(), ['filename' => $filename, 'heading' => 0])
        ->assertNotFound();
})->with(['missing.jpg', '_trashed_x_c.jpg', '../../202/pub/a.jpg']);

it('is for managers only', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'heading' => 0])
        ->assertForbidden();
});

it('lists photos without a scene type for recognition, optionally per import', function () {
    GalleryImageDescription::factory()->create(['filename' => 'a.jpg', 'scene' => Scene::Les, 'scene_score' => 0.8]);
    $admin = User::factory()->admin()->create();
    $queue = fn (array $query = []) => $this->actingAs($admin)
        ->getJson(route('gallery.scene-queue', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, ...$query]))
        ->assertOk()
        ->json('*.filename');

    expect($queue())->toBe(['b.jpg']);

    $import = ConfluenceImport::factory()->done()->create();
    ConfluenceImportItem::factory()->imported()->for($import, 'import')->create(['original_filename' => 'a.jpg']);
    expect($queue(['import' => $import->id]))->toBe([]);

    $other = ConfluenceImport::factory()->done()->create(['ap_id' => 202]);
    $this->actingAs($admin)
        ->getJson(route('gallery.scene-queue', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'import' => $other->id]))
        ->assertNotFound();
});

it('shows the compass and scene recognition to managers only', function () {
    $url = route('gallery.public', ['area' => 13, 'ap' => 201]);

    $this->get($url)->assertDontSee('data-heading-form', escape: false)->assertDontSee('data-scene-tagging', escape: false);

    $this->actingAs(User::factory()->admin()->create())->get($url)
        ->assertSee('data-heading-form', escape: false)
        ->assertSee('data-scene-tagging', escape: false)
        ->assertSee('Nastavit směr pohledu');
});

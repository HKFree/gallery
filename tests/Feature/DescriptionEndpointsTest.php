<?php

use App\Enums\Scene;
use App\Models\ConfluenceImport;
use App\Models\ConfluenceImportItem;
use App\Models\GalleryImageDescription;
use App\Models\GalleryImageEmbedding;
use App\Models\User;
use Illuminate\Support\Arr;
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

it('lists photos that still need analysing, optionally per import', function () {
    GalleryImageDescription::factory()->create(['filename' => 'a.jpg', 'scene' => Scene::Les, 'scene_score' => 0.8]);
    $admin = User::factory()->admin()->create();
    $queue = fn (array $query = []) => $this->actingAs($admin)
        ->getJson(route('gallery.analysis-queue', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, ...$query]))
        ->assertOk()
        ->json();

    GalleryImageEmbedding::factory()->create(['filename' => 'b.jpg']);

    expect(collect($queue())->map(fn (array $photo) => Arr::only($photo, ['filename', 'scene', 'embedding']))->all())->toBe([
        ['filename' => 'a.jpg', 'scene' => false, 'embedding' => true],
        ['filename' => 'b.jpg', 'scene' => true, 'embedding' => false],
    ]);

    $import = ConfluenceImport::factory()->done()->create();
    ConfluenceImportItem::factory()->imported()->for($import, 'import')->create(['original_filename' => 'a.jpg']);
    expect($queue(['import' => $import->id]))->toBe([]);

    $other = ConfluenceImport::factory()->done()->create(['ap_id' => 202]);
    $this->actingAs($admin)
        ->getJson(route('gallery.analysis-queue', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'import' => $other->id]))
        ->assertNotFound();
});

it('shows the compass and scene recognition to managers only', function () {
    $url = route('gallery.public', ['area' => 13, 'ap' => 201]);

    $this->get($url)->assertDontSee('data-heading-form', escape: false)->assertDontSee('data-photo-analysis', escape: false);

    $this->actingAs(User::factory()->admin()->create())->get($url)
        ->assertSee('data-heading-form', escape: false)
        ->assertSee('data-photo-analysis', escape: false)
        ->assertSee('Nastavit směr pohledu');
});

it('stores the measured obstruction, or that it could not be judged', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'scene' => 'krajina', 'score' => 0.9, 'obstruction' => 0.31, 'obstruction_kind' => 'trees'])
        ->assertOk()
        ->assertJson(['description' => 'Výhled do krajiny, stromy zakrývají asi 30 % výhledu (rozpoznáno automaticky).']);

    $this->actingAs($admin)
        ->postJson(descriptionUrl(), ['filename' => 'b.jpg', 'obstruction_kind' => 'unknown'])
        ->assertOk();

    expect(GalleryImageDescription::where('filename', 'b.jpg')->sole())->obstruction->toBeNull()->obstruction_kind->toBe('unknown');

    $this->actingAs($admin)->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'obstruction_kind' => 'trees'])->assertJsonValidationErrors('obstruction');
    $this->actingAs($admin)->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'obstruction_kind' => 'birds', 'obstruction' => 0.1])->assertJsonValidationErrors('obstruction_kind');
    $this->actingAs($admin)->postJson(descriptionUrl(), ['filename' => 'a.jpg', 'obstruction_kind' => 'trees', 'obstruction' => 2])->assertJsonValidationErrors('obstruction');
});

it('limits the analysis queue to given files, e.g. just uploaded ones', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('gallery.analysis-queue', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'files' => ['b.jpg', 'missing.jpg']]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.filename', 'b.jpg')
        ->assertJsonPath('0.obstruction', true);
});

it('offers analysis right after upload to managers', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertSee('data-analyse-after-upload', escape: false)
        ->assertSee('Po nahrání fotky analyzovat');
});

<?php

use App\Models\GalleryImage;
use App\Models\GalleryImageDescription;
use App\Models\GalleryImageEmbedding;
use App\Models\User;
use App\Services\DirectionSuggestions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

/**
 * Store a photo with an embedding and, optionally, a known heading.
 *
 * @param  list<float>  $vector
 */
function photoWithEmbedding(string $filename, array $vector, ?int $heading = null, string $visibility = 'pub'): void
{
    Storage::disk('local')->put("gallery/ap/13/201/{$visibility}/{$filename}", 'x');
    GalleryImageEmbedding::factory()->create(['visibility' => $visibility, 'filename' => $filename, 'vector' => $vector]);

    if ($heading !== null) {
        GalleryImageDescription::factory()->create(['visibility' => $visibility, 'filename' => $filename, 'heading' => $heading, 'heading_source' => 'manual']);
    }
}

function suggestionsFor(array $filenames): array
{
    return app(DirectionSuggestions::class)->forGallery('pub', 13, 201, $filenames);
}

it('suggests the direction of the most similar photo with a known direction', function () {
    photoWithEmbedding('south-summer.jpg', [1, 0, 0], 180);
    photoWithEmbedding('east-summer.jpg', [0, 1, 0], 90);
    photoWithEmbedding('south-winter.jpg', [0.95, 0.2, 0.1]);

    expect(suggestionsFor(['south-winter.jpg']))->toBe([
        'south-winter.jpg' => ['heading' => 180, 'from' => 'south-summer.jpg', 'similarity' => 0.973],
    ]);
});

it('makes no suggestion when the match is weak or not clearly ahead of another direction', function () {
    photoWithEmbedding('south.jpg', [1, 0, 0], 180);
    photoWithEmbedding('east.jpg', [0, 1, 0], 90);
    photoWithEmbedding('unlike-anything.jpg', [0, 0, 1]);
    photoWithEmbedding('in-between.jpg', [0.7, 0.68, 0.1]);

    expect(suggestionsFor(['unlike-anything.jpg', 'in-between.jpg']))->toBe([]);
});

it('does not let photos of the same direction compete with each other', function () {
    photoWithEmbedding('south-a.jpg', [1, 0, 0], 180);
    photoWithEmbedding('south-b.jpg', [0.98, 0.15, 0], 170);
    photoWithEmbedding('query.jpg', [0.99, 0.1, 0]);

    expect(suggestionsFor(['query.jpg'])['query.jpg']['heading'])->toBeIn([180, 170]);
});

it('learns from the private gallery of the same AP, and skips photos that have a direction', function () {
    photoWithEmbedding('docs.jpg', [1, 0, 0], 45, 'priv');
    photoWithEmbedding('known.jpg', [0, 1, 0], 300);
    photoWithEmbedding('query.jpg', [1, 0.02, 0]);

    $suggestions = suggestionsFor(['query.jpg', 'known.jpg']);

    expect($suggestions)->toHaveKey('query.jpg')->not->toHaveKey('known.jpg')
        ->and($suggestions['query.jpg'])->toMatchArray(['heading' => 45, 'from' => 'docs.jpg']);
});

it('ignores vectors of another model', function () {
    photoWithEmbedding('south.jpg', [1, 0, 0], 180);
    photoWithEmbedding('query.jpg', [1, 0, 0]);
    GalleryImageEmbedding::query()->update(['model' => 'other/model']);

    expect(suggestionsFor(['query.jpg']))->toBe([]);
});

it('stores embeddings sent by the browser and validates them', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'x');
    $admin = User::factory()->admin()->create();
    $url = route('gallery.embedding', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]);
    $vector = array_fill(0, DirectionSuggestions::DIMENSIONS, 0.5);

    $this->actingAs($admin)
        ->postJson($url, ['filename' => 'a.jpg', 'model' => DirectionSuggestions::MODEL, 'vector' => base64_encode(pack('g*', ...$vector))])
        ->assertOk();

    expect(GalleryImageEmbedding::sole()->vector)->toHaveCount(DirectionSuggestions::DIMENSIONS);

    $this->actingAs($admin)->postJson($url, ['filename' => 'a.jpg', 'model' => DirectionSuggestions::MODEL, 'vector' => base64_encode(pack('g*', 1.0, 2.0))])
        ->assertJsonValidationErrors('vector');
    $this->actingAs($admin)->postJson($url, ['filename' => 'a.jpg', 'model' => 'evil/model', 'vector' => 'x'])
        ->assertJsonValidationErrors('model');
    $this->actingAs(User::factory()->create())->postJson($url, ['filename' => 'a.jpg'])->assertForbidden();
});

it('shows suggestions to managers and confirms one or all of them', function () {
    photoWithEmbedding('south.jpg', [1, 0, 0], 180);
    photoWithEmbedding('a.jpg', [0.98, 0.1, 0]);
    photoWithEmbedding('b.jpg', [0.97, 0.15, 0]);
    $admin = User::factory()->admin()->create();
    $gallery = route('gallery.public', ['area' => 13, 'ap' => 201]);

    $this->get($gallery)->assertDontSee('Návrh:');

    $this->actingAs($admin)->get($gallery)
        ->assertSee('Návrh: J (180°)')
        ->assertSee('Potvrdit návrhy směru (2)');

    $this->actingAs($admin)
        ->postJson(route('gallery.description', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), ['filename' => 'a.jpg', 'heading' => 180, 'source' => 'similarity'])
        ->assertOk();

    expect(GalleryImageDescription::where('filename', 'a.jpg')->sole()->heading_source)->toBe('similarity');

    $this->actingAs($admin)->from($gallery)
        ->post(route('gallery.suggestions.confirm', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]))
        ->assertRedirect($gallery)
        ->assertSessionHas('status', 'Potvrzené návrhy směru: 1.');

    expect(GalleryImageDescription::where('filename', 'b.jpg')->sole())->heading->toBe(180)->heading_source->toBe('similarity');
});

it('shows suggestions on the AP timeline for managers', function () {
    photoWithEmbedding('south.jpg', [1, 0, 0], 180);
    photoWithEmbedding('a.jpg', [0.98, 0.1, 0]);
    GalleryImage::factory()->create(['filename' => 'a.jpg']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]))
        ->assertSee('Návrh: J (180°)');
});

it('forgets the embedding of a trashed photo', function () {
    photoWithEmbedding('a.jpg', [1, 0, 0]);

    $this->actingAs(User::factory()->admin()->create())
        ->deleteJson(route('gallery.destroy', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'filename' => 'a.jpg']))
        ->assertOk();

    expect(GalleryImageEmbedding::count())->toBe(0);
});

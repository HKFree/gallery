<?php

use App\Models\User;
use App\Services\GalleryStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => fakeUserdbAreas());

it('assembles an image from multiple chunks and generates a thumbnail', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    $image = UploadedFile::fake()->image('alpha.jpg', 600, 600);
    $contents = file_get_contents($image->getRealPath());

    // Force several chunks regardless of the encoded image's size.
    uploadGalleryChunks('pub', 13, 201, 'alpha.jpg', $contents, (int) ceil(strlen($contents) / 3))
        ->assertOk()
        ->assertJson(['status' => 'ok', 'filename' => 'alpha.jpg']);

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/alpha.jpg');
    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/thumbs/alpha.jpg');
    expect(Storage::disk('local')->files('gallery/tmp'))->toBeEmpty();
});

it('stores a small single-chunk image', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    $image = UploadedFile::fake()->image('beta.png');
    $contents = file_get_contents($image->getRealPath());

    uploadGalleryChunks('pub', 13, 201, 'beta.png', $contents)
        ->assertOk()
        ->assertJson(['status' => 'ok', 'filename' => 'beta.png']);

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/beta.png');
    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/thumbs/beta.png');
});

it('returns pending for intermediate chunks and ok for the final chunk', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    $image = UploadedFile::fake()->image('gamma.jpg', 400, 400);
    $contents = file_get_contents($image->getRealPath());
    [$first, $second] = str_split($contents, (int) ceil(strlen($contents) / 2));
    $uploadId = (string) Str::uuid();

    $base = ['upload_id' => $uploadId, 'total_chunks' => 2, 'filename' => 'gamma.jpg'];
    $url = route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]);
    $json = ['Accept' => 'application/json'];

    $this->post($url, [...$base, 'chunk_index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $first)], $json)
        ->assertOk()
        ->assertJson(['status' => 'pending']);

    $this->post($url, [...$base, 'chunk_index' => 1, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $second)], $json)
        ->assertOk()
        ->assertJson(['status' => 'ok', 'filename' => 'gamma.jpg']);
});

it('rejects an assembled file that is not a valid image and stores nothing', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    uploadGalleryChunks('pub', 13, 201, 'fake.jpg', 'this is plainly not an image')
        ->assertStatus(422)
        ->assertJson(['message' => 'Soubor není platný obrázek.']);

    Storage::disk('local')->assertMissing('gallery/ap/13/201/pub/fake.jpg');
    expect(Storage::disk('local')->files('gallery/tmp'))->toBeEmpty();
});

it('rejects a chunk larger than the per-request limit', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create())
        ->post(
            route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]),
            [
                'upload_id' => (string) Str::uuid(),
                'chunk_index' => 0,
                'total_chunks' => 1,
                'filename' => 'big.jpg',
                'chunk' => UploadedFile::fake()->create('chunk', 3000),
            ],
            ['Accept' => 'application/json'],
        )
        ->assertStatus(422)
        ->assertJsonValidationErrors('chunk');
});

it('soft-deletes by renaming into the trash and hides it from listings', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gallery/ap/13/201/pub/gone.jpg', 'x');

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('gallery.destroy', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'filename' => 'gone.jpg']))
        ->assertRedirect();

    Storage::disk('local')->assertMissing('gallery/ap/13/201/pub/gone.jpg');

    expect(app(GalleryStorage::class)->imageNames('pub', 13, 201))->not->toContain('gone.jpg')
        ->and(collect(Storage::disk('local')->files('gallery/ap/13/201/pub'))
            ->contains(fn (string $path) => str_contains($path, '_trashed_')))->toBeTrue();
});

it('forbids non-SO users from uploading', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->create())
        ->post(route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), [
            'files' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertForbidden();
});

it('redirects guests who attempt to upload to login', function () {
    $this->post(route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), [])
        ->assertRedirect(route('login'));
});

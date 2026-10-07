<?php

use App\Models\User;
use App\Services\GalleryStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

it('rejects chunks that arrive out of order or are duplicated', function (array $sequence) {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    $uploadId = (string) Str::uuid();
    $url = route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]);
    $post = fn (int $index) => $this->post($url, [
        'upload_id' => $uploadId,
        'chunk_index' => $index,
        'total_chunks' => 3,
        'filename' => 'delta.jpg',
        'chunk' => UploadedFile::fake()->createWithContent('chunk', 'piece'),
    ], ['Accept' => 'application/json']);

    $rejected = array_pop($sequence);

    foreach ($sequence as $index) {
        $post($index)->assertOk()->assertJson(['status' => 'pending']);
    }

    $post($rejected)
        ->assertStatus(422)
        ->assertJson(['message' => 'Chunk out of order or upload session expired.']);
})->with([
    'skipped chunk' => [[0, 2]],
    'duplicated chunk' => [[0, 1, 1]],
]);

it('stores an image too large to thumbnail without a thumbnail instead of crashing', function () {
    Storage::fake('local');
    Log::spy();
    $this->actingAs(User::factory()->admin()->create());

    // A PNG whose header claims 20000x20000 px: decoding it would need gigabytes of memory.
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 20000, 20000, 8, 6, 0, 0, 0))
        .$chunk('IDAT', str_repeat("\0", 64))
        .$chunk('IEND', '');

    uploadGalleryChunks('pub', 13, 201, 'huge.png', $png)
        ->assertOk()
        ->assertJson(['status' => 'ok', 'filename' => 'huge.png']);

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/huge.png');
    Storage::disk('local')->assertMissing('gallery/ap/13/201/pub/thumbs/huge.png');
    expect(Storage::disk('local')->files('gallery/tmp'))->toBeEmpty();
    Log::shouldHaveReceived('warning')->with('Gallery: image too large to generate a thumbnail', Mockery::any());
});

it('counts every frame of an animated GIF when estimating thumbnail memory', function () {
    Storage::fake('local');
    Log::spy();
    $this->actingAs(User::factory()->admin()->create());

    // 2000x2000 px is affordable as one frame, but not as 40 full-canvas frames.
    $frame = "\x21\xF9\x04\x00\x00\x00\x00\x00"
        ."\x2C".pack('vvvv', 0, 0, 1, 1)."\x00"
        ."\x02\x02\x4C\x01\x00";
    $gif = 'GIF89a'.pack('vv', 2000, 2000)."\x00\x00\x00".str_repeat($frame, 40).';';

    uploadGalleryChunks('pub', 13, 201, 'anim.gif', $gif)
        ->assertOk()
        ->assertJson(['status' => 'ok', 'filename' => 'anim.gif']);

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/anim.gif');
    Storage::disk('local')->assertMissing('gallery/ap/13/201/pub/thumbs/anim.gif');
    Log::shouldHaveReceived('warning')->with('Gallery: image too large to generate a thumbnail', Mockery::any());
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

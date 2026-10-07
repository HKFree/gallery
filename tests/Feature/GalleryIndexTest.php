<?php

use App\Models\GalleryImage;
use App\Models\User;
use App\Services\GalleryIndex;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

it('indexes an upload by its EXIF taken date', function () {
    $this->actingAs(User::factory()->admin()->create());

    uploadGalleryChunks('pub', 13, 201, 'trip.jpg', jpegWithExif('2021:07:14 09:30:00'))->assertOk();

    $image = GalleryImage::sole();

    expect($image->only(['visibility', 'area_id', 'ap_id', 'filename']))
        ->toBe(['visibility' => 'pub', 'area_id' => 13, 'ap_id' => 201, 'filename' => 'trip.jpg'])
        ->and($image->taken_at->format('Y-m-d H:i:s'))->toBe('2021-07-14 09:30:00')
        ->and($image->sort_at->format('Y-m-d H:i:s'))->toBe('2021-07-14 09:30:00')
        ->and($image->sort_month)->toBe('2021-07');
});

it('falls back to the browser modification time when the upload has no EXIF date', function () {
    $this->actingAs(User::factory()->admin()->create());

    $image = UploadedFile::fake()->image('plain.jpg');
    $lastModified = CarbonImmutable::parse('2019-03-10 12:00:00', 'UTC');

    $this->post(route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), [
        'upload_id' => (string) Str::uuid(),
        'chunk_index' => 0,
        'total_chunks' => 1,
        'filename' => 'plain.jpg',
        'client_modified_at' => $lastModified->getTimestampMs(),
        'chunk' => UploadedFile::fake()->createWithContent('chunk', file_get_contents($image->getRealPath())),
    ], ['Accept' => 'application/json'])->assertOk();

    expect(GalleryImage::sole())
        ->taken_at->toBeNull()
        ->sort_at->format('Y-m-d H:i')->toBe('2019-03-10 13:00')
        ->sort_month->toBe('2019-03');
});

it('falls back to the upload time without EXIF or a browser date', function () {
    $this->travelTo(CarbonImmutable::parse('2025-06-30 22:30:00', 'UTC'));
    $this->actingAs(User::factory()->admin()->create());

    $image = UploadedFile::fake()->image('plain.png');
    uploadGalleryChunks('pub', 13, 201, 'plain.png', file_get_contents($image->getRealPath()))->assertOk();

    // The file's mtime is the real clock, so only check it is used as the sort date.
    $indexed = GalleryImage::sole();

    expect($indexed->taken_at)->toBeNull()
        ->and($indexed->client_modified_at)->toBeNull()
        ->and($indexed->sort_at->equalTo($indexed->uploaded_at))->toBeTrue();
});

it('keeps the upload when indexing fails', function () {
    $this->mock(GalleryIndex::class)->shouldReceive('record')->andThrow(new RuntimeException('db down'));
    $this->actingAs(User::factory()->admin()->create());

    $image = UploadedFile::fake()->image('safe.jpg');
    uploadGalleryChunks('pub', 13, 201, 'safe.jpg', file_get_contents($image->getRealPath()))
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/safe.jpg');
});

it('removes a trashed image from the index', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/gone.jpg', 'x');
    GalleryImage::factory()->create(['filename' => 'gone.jpg']);
    $kept = GalleryImage::factory()->create(['filename' => 'kept.jpg']);

    $this->actingAs(User::factory()->admin()->create())
        ->deleteJson(route('gallery.destroy', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'filename' => 'gone.jpg']))
        ->assertOk();

    expect(GalleryImage::pluck('id')->all())->toBe([$kept->id]);
});

it('records the same image only once', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', jpegWithExif('2020:01:02 03:04:05'));
    $index = app(GalleryIndex::class);

    $index->record('pub', 13, 201, 'a.jpg');
    $index->record('pub', 13, 201, 'a.jpg');

    expect(GalleryImage::count())->toBe(1);
});

it('reconciles a gallery: indexes new files and drops rows whose file is gone', function () {
    $disk = Storage::disk('local');
    $disk->put('gallery/ap/13/201/pub/new.jpg', jpegWithExif('2020:01:02 03:04:05'));
    $disk->put('gallery/ap/13/201/pub/indexed.jpg', 'x');
    $disk->put('gallery/ap/13/201/pub/_trashed_20260101000000_old.jpg', 'x');
    $disk->put('gallery/ap/13/201/pub/thumbs/new.jpg', 'x');
    $disk->put('gallery/ap/13/201/pub/notes.txt', 'x');

    GalleryImage::factory()->create(['filename' => 'indexed.jpg']);
    GalleryImage::factory()->create(['filename' => 'missing.jpg']);
    GalleryImage::factory()->private()->create(['filename' => 'other-gallery.jpg']);

    $result = app(GalleryIndex::class)->reconcileAp('pub', 13, 201);

    expect($result)->toMatchArray(['added' => 1, 'removed' => 1, 'pending' => 0, 'taken' => 1, 'months' => ['2020-01' => 1]])
        ->and(GalleryImage::where('visibility', 'pub')->orderBy('filename')->pluck('filename')->all())->toBe(['indexed.jpg', 'new.jpg'])
        ->and(GalleryImage::where('visibility', 'priv')->count())->toBe(1);
});

it('caps how many files one reconcile indexes and reports the rest as pending', function () {
    foreach (['a', 'b', 'c'] as $name) {
        Storage::disk('local')->put("gallery/ap/13/201/pub/{$name}.jpg", 'x');
    }

    $result = app(GalleryIndex::class)->reconcileAp('pub', 13, 201, limit: 2);

    expect($result)->toMatchArray(['added' => 2, 'pending' => 1])
        ->and(GalleryImage::count())->toBe(2);
});

it('dates files by mtime in gallery time, across a month boundary', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/late.jpg', 'x');
    touch(Storage::disk('local')->path('gallery/ap/13/201/pub/late.jpg'), CarbonImmutable::parse('2024-01-31 23:30:00', 'UTC')->getTimestamp());

    app(GalleryIndex::class)->reconcileAp('pub', 13, 201);

    expect(GalleryImage::sole())
        ->uploaded_at->format('Y-m-d H:i')->toBe('2024-02-01 00:30')
        ->sort_month->toBe('2024-02');
});

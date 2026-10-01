<?php

use App\Models\GalleryImage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $disk = Storage::disk('local');
    $disk->put('gallery/ap/13/201/pub/a.jpg', jpegWithExif('2022:03:04 05:06:07'));
    $disk->put('gallery/ap/13/201/priv/b.jpg', jpegWithExif('2023:04:05 06:07:08'));
    $disk->put('gallery/ap/12/101/pub/c.jpg', 'x');
    $disk->put('gallery/ap/12/101/pub/thumbs/c.jpg', 'x');
    $disk->put('gallery/ap/12/101/pub/_trashed_20260101000000_d.jpg', 'x');
    $disk->put('gallery/tmp/upload.part', 'x');
});

it('indexes every gallery on disk and drops rows of removed galleries', function () {
    GalleryImage::factory()->create(['area_id' => 99, 'ap_id' => 999, 'filename' => 'orphan.jpg']);

    $this->artisan('gallery:index')
        ->expectsOutputToContain('Gallery index synced.')
        ->assertSuccessful();

    expect(GalleryImage::orderBy('filename')->get()->map->only(['visibility', 'area_id', 'ap_id', 'filename'])->all())->toBe([
        ['visibility' => 'pub', 'area_id' => 13, 'ap_id' => 201, 'filename' => 'a.jpg'],
        ['visibility' => 'priv', 'area_id' => 13, 'ap_id' => 201, 'filename' => 'b.jpg'],
        ['visibility' => 'pub', 'area_id' => 12, 'ap_id' => 101, 'filename' => 'c.jpg'],
    ]);
});

it('limits reconciling to one area or AP', function () {
    $this->artisan('gallery:index', ['--area' => 13])->assertSuccessful();

    expect(GalleryImage::orderBy('filename')->pluck('filename')->all())->toBe(['a.jpg', 'b.jpg']);
});

it('writes nothing on a dry run and reports the month distribution', function () {
    $this->artisan('gallery:index', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing was written.')
        ->expectsOutputToContain('2022-03')
        ->expectsOutputToContain('2023-04')
        ->assertSuccessful();

    expect(GalleryImage::count())->toBe(0);
});

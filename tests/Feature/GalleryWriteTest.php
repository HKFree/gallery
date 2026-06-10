<?php

use App\Models\User;
use App\Services\GalleryStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakeUserdbAreas());

it('lets an SO user upload multiple images and generates thumbnails', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), [
            'files' => [
                UploadedFile::fake()->image('alpha.jpg'),
                UploadedFile::fake()->image('beta.png'),
            ],
        ])->assertRedirect();

    Storage::disk('public')->assertExists('gallery/13/201/alpha.jpg');
    Storage::disk('public')->assertExists('gallery/13/201/thumbs/alpha.jpg');
    Storage::disk('public')->assertExists('gallery/13/201/beta.png');
    Storage::disk('public')->assertExists('gallery/13/201/thumbs/beta.png');
});

it('soft-deletes by renaming into the trash and hides it from listings', function () {
    Storage::fake('public');
    Storage::disk('public')->put('gallery/13/201/gone.jpg', 'x');

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('gallery.destroy', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'filename' => 'gone.jpg']))
        ->assertRedirect();

    Storage::disk('public')->assertMissing('gallery/13/201/gone.jpg');

    expect(app(GalleryStorage::class)->imageNames('pub', 13, 201))->not->toContain('gone.jpg')
        ->and(collect(Storage::disk('public')->files('gallery/13/201'))
            ->contains(fn (string $path) => str_contains($path, '_trashed_')))->toBeTrue();
});

it('forbids non-SO users from uploading', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create())
        ->post(route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), [
            'files' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertForbidden();
});

it('redirects guests who attempt to upload to login', function () {
    $this->post(route('gallery.upload', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), [])
        ->assertRedirect(route('login'));
});

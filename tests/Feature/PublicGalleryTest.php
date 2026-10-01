<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

it('shows public images sorted by name and hides trashed files', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/b.jpg', 'x');
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'x');
    Storage::disk('local')->put('gallery/ap/13/201/pub/_trashed_20260101000000_c.jpg', 'x');

    $response = $this->get(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertSuccessful()
        ->assertSee('a.jpg')
        ->assertSee('b.jpg')
        ->assertDontSee('_trashed_');

    expect(strpos($response->getContent(), 'a.jpg'))
        ->toBeLessThan(strpos($response->getContent(), 'b.jpg'));
});

it('shows the locked Dokumentace link to the private gallery', function () {
    $this->get(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertSee('Dokumentace')
        ->assertSee(route('gallery.private', ['area' => 13, 'ap' => 201]));
});

it('hides manage controls from guests', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'x');

    $this->get(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertDontSee('data-dropzone', escape: false)
        ->assertDontSee('data-delete-url', escape: false);
});

it('serves public images with cache headers and answers revalidation with 304', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'data');

    $url = route('gallery.public.image', ['area' => 13, 'ap' => 201, 'filename' => 'a.jpg']);

    $response = $this->get($url)->assertSuccessful();
    $etag = $response->headers->get('ETag');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=86400')
        ->and($etag)->not->toBeEmpty()
        ->and($response->headers->get('Last-Modified'))->not->toBeEmpty();

    $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
});

it('returns 404 for an unknown AP', function () {
    $this->get(route('gallery.public', ['area' => 13, 'ap' => 999]))
        ->assertNotFound();
});

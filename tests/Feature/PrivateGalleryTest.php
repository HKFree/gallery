<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakeUserdbAreas());

it('redirects guests from the private gallery to login', function () {
    $this->get(route('gallery.private', ['area' => 13, 'ap' => 201]))
        ->assertRedirect(route('login'));
});

it('lets any authenticated user view the private gallery without manage controls', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gallery-private/13/201/doc.jpg', 'x');

    $this->actingAs(User::factory()->create())
        ->get(route('gallery.private', ['area' => 13, 'ap' => 201]))
        ->assertSuccessful()
        ->assertSee('doc.jpg')
        ->assertDontSee('data-dropzone', escape: false);
});

it('streams private images only to authenticated users', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gallery-private/13/201/doc.jpg', 'data');

    $url = route('gallery.private.image', ['area' => 13, 'ap' => 201, 'filename' => 'doc.jpg']);

    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get($url)->assertSuccessful();
});

it('does not serve trashed private files', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gallery-private/13/201/_trashed_x_doc.jpg', 'data');

    $this->actingAs(User::factory()->create())
        ->get(route('gallery.private.image', ['area' => 13, 'ap' => 201, 'filename' => '_trashed_x_doc.jpg']))
        ->assertNotFound();
});

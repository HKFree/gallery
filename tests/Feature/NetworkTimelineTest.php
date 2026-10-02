<?php

use App\Models\GalleryImage;
use App\Models\User;
use App\Services\Timeline;

beforeEach(function () {
    fakeUserdbAreas();

    GalleryImage::factory()->create(['area_id' => 13, 'ap_id' => 201, 'filename' => 'brno.jpg', 'taken_at' => '2024-05-20 10:00:00']);
    GalleryImage::factory()->create(['area_id' => 13, 'ap_id' => 202, 'filename' => 'sever.jpg', 'taken_at' => '2024-04-10 10:00:00']);
    GalleryImage::factory()->private()->create(['area_id' => 12, 'ap_id' => 101, 'filename' => 'secret.jpg', 'taken_at' => '2024-05-01 10:00:00']);
    GalleryImage::factory()->create(['area_id' => 99, 'ap_id' => 999, 'filename' => 'orphan.jpg', 'taken_at' => '2024-05-02 10:00:00']);
});

it('shows public images of all APs, newest first, captioned with their AP', function () {
    $this->get(route('timeline'))
        ->assertSuccessful()
        ->assertSeeInOrder(['Květen 2024', 'brno.jpg', 'Duben 2024', 'sever.jpg'])
        ->assertSee('Brno-Sever')
        ->assertSee(route('gallery.public.timeline', ['area' => 13, 'ap' => 202]));
});

it('never shows private images to guests, even when asked to', function () {
    $this->get(route('timeline', ['priv' => 1]))
        ->assertSuccessful()
        ->assertDontSee('secret.jpg')
        ->assertDontSee('Zobrazit i Dokumentaci');
});

it('shows private images to signed-in users who opt in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('timeline'))
        ->assertDontSee('secret.jpg')
        ->assertSee('Zobrazit i Dokumentaci');

    $this->actingAs($user)->get(route('timeline', ['priv' => 1]))
        ->assertSee('secret.jpg')
        ->assertSee(route('gallery.private.image', ['area' => 12, 'ap' => 101, 'filename' => 'secret.jpg']))
        ->assertSee(route('timeline', ['priv' => 1, 'from' => '2024-05']));
});

it('leaves out images of APs no longer listed in Userdb', function () {
    $this->get(route('timeline'))->assertDontSee('orphan.jpg');
});

it('keeps the private opt-in on further pages', function () {
    GalleryImage::factory()->count(Timeline::PER_PAGE)->create(['taken_at' => '2025-01-01 10:00:00']);

    $page = $this->actingAs(User::factory()->create())->get(route('timeline', ['priv' => 1]))->getContent();

    preg_match('/data-timeline-next href="([^"]+)"/', $page, $next);

    expect(html_entity_decode($next[1]))->toContain('priv=1');
});

it('offers no manage controls', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('timeline'))
        ->assertDontSee('data-delete-url', escape: false);
});

it('is linked from the header', function () {
    $this->get(route('home'))->assertSee(route('timeline'));
});

it('leaves out images filed under an area the AP no longer belongs to', function () {
    // AP 201 is in area 13 in Userdb; this row says area 12, so its links would 404.
    GalleryImage::factory()->create(['area_id' => 12, 'ap_id' => 201, 'filename' => 'moved.jpg', 'taken_at' => '2024-05-21 10:00:00']);

    $this->get(route('timeline'))->assertDontSee('moved.jpg')->assertSee('brno.jpg');
});

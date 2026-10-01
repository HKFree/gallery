<?php

use App\Models\GalleryImage;
use App\Models\User;
use App\Services\Timeline;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

/**
 * Store an image on disk and index it with the given taken date.
 */
function timelineImage(string $filename, string $takenAt, string $visibility = 'pub'): GalleryImage
{
    Storage::disk('local')->put("gallery/ap/13/201/{$visibility}/{$filename}", 'x');

    return GalleryImage::factory()->create(['visibility' => $visibility, 'filename' => $filename, 'taken_at' => $takenAt]);
}

it('groups images by month, newest first, with Czech headings and month counts', function () {
    timelineImage('older.jpg', '2023-11-02 10:00:00');
    timelineImage('newer.jpg', '2024-05-17 10:00:00');
    timelineImage('newest.jpg', '2024-05-20 10:00:00');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]))
        ->assertSuccessful()
        ->assertSeeInOrder(['květen 2024', 'newest.jpg', 'newer.jpg', 'listopad 2023', 'older.jpg'])
        ->assertSee('data-month="2024-05"', escape: false)
        ->assertSee(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]).'?from=2023-11', escape: false);
});

it('indexes files found on disk when the timeline is opened', function () {
    Storage::disk('local')->put('gallery/ap/13/201/pub/copied.jpg', jpegWithExif('2020:02:03 04:05:06'));
    Storage::disk('local')->put('gallery/ap/13/201/pub/_trashed_20260101000000_gone.jpg', 'x');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]))
        ->assertSee('únor 2020')
        ->assertSee('copied.jpg')
        ->assertDontSee('gone.jpg');
});

it('pages through all images exactly once with the Starší link', function () {
    foreach (range(1, Timeline::PER_PAGE + 5) as $day) {
        timelineImage(sprintf('img-%03d.jpg', $day), now()->subDays($day)->format('Y-m-d 12:00:00'));
    }

    $url = route('gallery.public.timeline', ['area' => 13, 'ap' => 201]);
    $seen = [];

    while ($url !== null) {
        $page = $this->get($url)->assertSuccessful()->getContent();
        preg_match_all('/alt="(img-\d+\.jpg)"/', $page, $matches);
        $seen = [...$seen, ...$matches[1]];
        $url = preg_match('/data-timeline-next href="([^"]+)"/', $page, $next) ? html_entity_decode($next[1]) : null;
    }

    expect($seen)->toHaveCount(Timeline::PER_PAGE + 5)
        ->and(array_unique($seen))->toHaveCount(Timeline::PER_PAGE + 5);
});

it('serves just the next page as a fragment to script requests', function () {
    timelineImage('a.jpg', '2024-05-17 10:00:00');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertSuccessful()
        ->assertSee('data-month="2024-05"', escape: false)
        ->assertDontSee('<html', escape: false);
});

it('starts at the requested month and links back to the newest', function () {
    timelineImage('newer.jpg', '2024-05-17 10:00:00');
    timelineImage('older.jpg', '2023-11-02 10:00:00');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201, 'from' => '2024-01']))
        ->assertSee('older.jpg')
        ->assertDontSee('newer.jpg')
        ->assertSee('Novější');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201, 'from' => '2024-05']))
        ->assertSee('newer.jpg')
        ->assertDontSee('Novější');
});

it('ignores a malformed month', function () {
    timelineImage('a.jpg', '2024-05-17 10:00:00');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201, 'from' => "2024-05' OR 1=1"]))
        ->assertSuccessful()
        ->assertSee('a.jpg');
});

it('keeps galleries separate', function () {
    timelineImage('public.jpg', '2024-05-17 10:00:00');
    timelineImage('private.jpg', '2024-05-17 10:00:00', 'priv');

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]))
        ->assertSee('public.jpg')
        ->assertDontSee('private.jpg');
});

it('requires login for the private timeline', function () {
    timelineImage('private.jpg', '2024-05-17 10:00:00', 'priv');
    $url = route('gallery.private.timeline', ['area' => 13, 'ap' => 201]);

    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertSuccessful()
        ->assertSee('private.jpg');
});

it('shows manage controls only to managers', function () {
    timelineImage('a.jpg', '2024-05-17 10:00:00');
    $url = route('gallery.public.timeline', ['area' => 13, 'ap' => 201]);

    $this->get($url)->assertDontSee('data-delete-url', escape: false)->assertDontSee('data-dropzone', escape: false);

    $this->actingAs(User::factory()->admin()->create())
        ->get($url)
        ->assertSee('data-delete-url', escape: false)
        ->assertSee('data-dropzone', escape: false);
});

it('links grid and timeline views to each other', function () {
    $this->get(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertSee(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]));

    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 201]))
        ->assertSee(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertSee('Zatím zde nejsou žádné obrázky.');
});

it('returns 404 for an unknown AP', function () {
    $this->get(route('gallery.public.timeline', ['area' => 13, 'ap' => 999]))->assertNotFound();
});

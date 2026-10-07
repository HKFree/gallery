<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

const PAGE_URL = 'https://doc.hkfree.org/spaces/fotogalerie/pages/22020482/Foto+v%C3%BDhled';

beforeEach(function () {
    fakeUserdbAreas();
});

function importUrl(array $query = [], string $visibility = 'pub'): string
{
    return route('confluence.import.create', ['visibility' => $visibility, 'area' => 13, 'ap' => 201, ...$query]);
}

it('is only for gallery managers', function () {
    $this->get(importUrl())->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(importUrl())->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())->get(importUrl())
        ->assertSuccessful()
        ->assertSee('Import z Confluence')
        ->assertSee('Adresa stránky s fotkami');
});

it('is linked from the gallery for managers', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('gallery.public', ['area' => 13, 'ap' => 201]))
        ->assertSee(importUrl());
});

it('previews the photos of a page without writing anything', function () {
    fakeConfluencePage(ancestors: ['Home', 'Brno']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(importUrl(['url' => PAGE_URL]))
        ->assertSuccessful()
        ->assertSee('Foto výhled')
        ->assertSeeInOrder(['Fotek', '11', 'Celková velikost', '73,5 MB'])
        ->assertDontSee('neodpovídá AP');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/download/'));
});

it('warns when the page does not look like this AP', function () {
    fakeConfluencePage(ancestors: ['Home', 'Benesovka']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(importUrl(['url' => PAGE_URL]))
        ->assertSee('neodpovídá AP Brno');
});

it('lists child pages of an index page as import links', function () {
    fakeConfluencePage(body: '<p>rozcestník</p>', attachments: [], children: [['id' => 555, 'title' => 'vyhledy_2026']], title: 'Brno');

    $this->actingAs(User::factory()->admin()->create())
        ->get(importUrl(['url' => PAGE_URL]))
        ->assertSee('neobsahuje žádné fotky')
        ->assertSee('vyhledy_2026')
        ->assertSee(importUrl(['url' => 'https://doc.hkfree.org/pages/viewpage.action?pageId=555']));
});

it('lists skipped images with their reasons', function () {
    fakeConfluencePage(body: '<ac:image><ri:url ri:value="https://example.org/x.jpg"/></ac:image>', attachments: []);

    $this->actingAs(User::factory()->admin()->create())
        ->get(importUrl(['url' => PAGE_URL]))
        ->assertSee('Přeskočeno: 1')
        ->assertSee('externí obrázek');
});

it('explains unsupported addresses and Confluence errors', function () {
    Http::fake(['doc.hkfree.org/*' => Http::response([], 404)]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(importUrl(['url' => 'https://evil.example.org/spaces/x/pages/1/y']))
        ->assertSee('Nepodporovaná adresa. Vložte odkaz na stránku z doc.hkfree.org.');

    $this->actingAs($admin)->get(importUrl(['url' => PAGE_URL]))
        ->assertSee('Stránka nebyla nalezena nebo k ní není přístup.');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.org'));
});

it('returns 404 for an unknown AP', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('confluence.import.create', ['visibility' => 'pub', 'area' => 13, 'ap' => 999]))
        ->assertNotFound();
});

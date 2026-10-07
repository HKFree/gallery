<?php

use App\Services\Confluence\ConfluenceClient;
use App\Services\Confluence\ConfluenceException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('extracts the page id from supported URLs on the configured host', function (string $url, ?int $expected) {
    Http::fake(['doc.hkfree.org/rest/api/content?*' => Http::response(['results' => [['id' => '777']]])]);

    expect(app(ConfluenceClient::class)->pageIdFromUrl($url))->toBe($expected);
})->with([
    'page URL' => ['https://doc.hkfree.org/spaces/fotogalerie/pages/22020482/Foto+v%C3%BDhled', 22020482],
    'page URL without title' => ['https://doc.hkfree.org/spaces/fotogalerie/pages/22020482', 22020482],
    'viewpage' => ['https://doc.hkfree.org/pages/viewpage.action?pageId=26804382', 26804382],
    'blog post' => ['https://doc.hkfree.org/spaces/fotogalerie/blog/2012/08/12/36306945/V%C3%BDhledy+z+AP+Kun%C4%8Dice', 36306945],
    'display (looked up)' => ['https://doc.hkfree.org/display/fotogalerie/Foto+v%C3%BDhled', 777],
    'other host' => ['https://evil.example.org/spaces/fotogalerie/pages/22020482/x', null],
    'look-alike host' => ['https://doc.hkfree.org.evil.example/spaces/x/pages/1/x', null],
    'non-http scheme' => ['file://doc.hkfree.org/spaces/x/pages/1/x', null],
    'non-numeric id' => ['https://doc.hkfree.org/pages/viewpage.action?pageId=1;DROP', null],
    'not a page' => ['https://doc.hkfree.org/spaces/fotogalerie/overview', null],
    'garbage' => ['not a url', null],
]);

it('looks display URLs up by space and title', function () {
    Http::fake(['doc.hkfree.org/rest/api/content?*' => Http::response(['results' => [['id' => '777']]])]);

    app(ConfluenceClient::class)->pageIdFromUrl('https://doc.hkfree.org/display/fotogalerie/Foto+v%C3%BDhled');

    Http::assertSent(fn (Request $request) => $request['spaceKey'] === 'fotogalerie' && $request['title'] === 'Foto výhled');
});

it('reads a page and its attachments', function () {
    fakeConfluencePage();
    $client = app(ConfluenceClient::class);

    $page = $client->page(22020482);
    $attachments = $client->attachments(22020482);

    expect($page)
        ->title->toBe('Foto výhled')
        ->spaceKey->toBe('fotogalerie')
        ->ancestors->toBe(['Home', 'Benesovka'])
        ->body->toContain('ac:name="gallery"')
        ->url->toStartWith('https://doc.hkfree.org/')
        ->and($attachments)->toHaveCount(11)
        ->and($attachments[0])
        ->filename->toBe('_DSC4245.JPG')
        ->mediaType->toBe('image/jpeg')
        ->size->toBe(8408582)
        ->and($attachments[0]->createdAt->format('Y-m-d'))->toBe('2011-05-25')
        ->and($attachments[0]->downloadPath)->toStartWith('/download/attachments/22020482/');
});

it('pages through many attachments', function () {
    $first = array_map(fn (int $i): array => confluenceAttachment("a{$i}.jpg"), range(1, 200));
    $second = [confluenceAttachment('last.jpg')];

    Http::fake(['doc.hkfree.org/rest/api/content/5/child/attachment*' => Http::sequence()
        ->push(['results' => $first])
        ->push(['results' => $second])]);

    expect(app(ConfluenceClient::class)->attachments(5))->toHaveCount(201);
});

it('reports missing pages and unreachable servers as Confluence errors', function (int $status, string $message) {
    Sleep::fake();
    Http::fake(['doc.hkfree.org/*' => Http::response([], $status)]);

    expect(fn () => app(ConfluenceClient::class)->page(1))->toThrow(ConfluenceException::class, $message);
})->with([
    'not found' => [404, 'Stránka nebyla nalezena'],
    'forbidden' => [403, 'Stránka nebyla nalezena'],
    'server error' => [500, 'Confluence odpověděl chybou 500'],
]);

it('does not follow redirects away from Confluence', function () {
    Sleep::fake();
    Http::fake(['doc.hkfree.org/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

    expect(fn () => app(ConfluenceClient::class)->page(1))->toThrow(ConfluenceException::class);

    Http::assertSentCount(1);
});

it('sends the access token only when one is configured', function () {
    fakeConfluencePage();

    app(ConfluenceClient::class)->page(22020482);
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));

    config(['services.confluence.token' => 'secret-token']);
    app(ConfluenceClient::class)->page(22020482);
    Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer secret-token']);
});

it('reads blog posts like pages, but not other content', function () {
    $page = json_decode(file_get_contents(base_path('tests/Fixtures/confluence/page-22020482.json')), true);

    Http::fake([
        'doc.hkfree.org/rest/api/content/7*' => Http::response([...$page, 'id' => '7', 'type' => 'blogpost']),
        'doc.hkfree.org/rest/api/content/8*' => Http::response([...$page, 'id' => '8', 'type' => 'attachment']),
    ]);

    expect(app(ConfluenceClient::class)->page(7)->id)->toBe(7)
        ->and(fn () => app(ConfluenceClient::class)->page(8))->toThrow(ConfluenceException::class, 'Adresa nevede na stránku Confluence.');
});

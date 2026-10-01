<?php

use App\Services\Confluence\ConfluenceAttachment;
use App\Services\Confluence\ConfluenceClient;
use App\Services\Confluence\ConfluencePage;
use App\Services\Confluence\PageAnalysis;
use App\Services\Confluence\PageAnalyzer;
use Carbon\CarbonImmutable;

/**
 * Analyze a storage-format body against the given attachments, without any HTTP.
 *
 * @param  list<ConfluenceAttachment>  $attachments
 */
function analyzePage(string $body, array $attachments): PageAnalysis
{
    $page = new ConfluencePage(1, 'Foto výhled', 1, 'fotogalerie', 'Fotogalerie', ['Home'], $body, 'https://doc.hkfree.org/x');

    return app(PageAnalyzer::class)->analyze($page, $attachments);
}

function attachment(string $filename, string $mediaType = 'image/jpeg', int $size = 1000, string $created = '2020-01-01T10:00:00Z'): ConfluenceAttachment
{
    return new ConfluenceAttachment(crc32($filename), $filename, $mediaType, $size, 1, CarbonImmutable::parse($created), "/download/attachments/1/{$filename}");
}

/**
 * @return list<string>
 */
function photoNames(PageAnalysis $analysis): array
{
    return array_map(fn ($photo) => $photo->filename, $analysis->photos);
}

function galleryMacro(string $parameters = ''): string
{
    return '<ac:structured-macro ac:name="gallery" ac:schema-version="1">'.$parameters.'</ac:structured-macro>';
}

it('takes all image attachments of a gallery macro, sorted by name', function () {
    fakeConfluencePage();
    $client = app(ConfluenceClient::class);

    $analysis = app(PageAnalyzer::class)->analyze($client->page(22020482), $client->attachments(22020482));

    expect(photoNames($analysis))->toHaveCount(11)
        ->and(photoNames($analysis)[0])->toBe('_DSC4232.JPG')
        ->and($analysis->skipped)->toBe([])
        ->and($analysis->totalSize())->toBeGreaterThan(70_000_000);
});

it('applies the gallery include, exclude, sort and reverse parameters', function () {
    $attachments = [
        attachment('b.jpg', created: '2020-01-03T00:00:00Z'),
        attachment('a.jpg', created: '2020-01-02T00:00:00Z'),
        attachment('c.jpg', created: '2020-01-01T00:00:00Z'),
        attachment('notes.pdf', 'application/pdf'),
    ];

    expect(photoNames(analyzePage(galleryMacro(), $attachments)))->toBe(['a.jpg', 'b.jpg', 'c.jpg'])
        ->and(photoNames(analyzePage(galleryMacro('<ac:parameter ac:name="include">c.jpg, a.jpg</ac:parameter>'), $attachments)))->toBe(['a.jpg', 'c.jpg'])
        ->and(photoNames(analyzePage(galleryMacro('<ac:parameter ac:name="exclude">b.jpg</ac:parameter>'), $attachments)))->toBe(['a.jpg', 'c.jpg'])
        ->and(photoNames(analyzePage(galleryMacro('<ac:parameter ac:name="sort">date</ac:parameter>'), $attachments)))->toBe(['c.jpg', 'a.jpg', 'b.jpg'])
        ->and(photoNames(analyzePage(galleryMacro('<ac:parameter ac:name="reverse">true</ac:parameter>'), $attachments)))->toBe(['c.jpg', 'b.jpg', 'a.jpg']);
});

it('accepts include lists given as attachment elements', function () {
    $body = galleryMacro('<ac:parameter ac:name="include"><ri:attachment ri:filename="b.jpg"/></ac:parameter>');

    expect(photoNames(analyzePage($body, [attachment('a.jpg'), attachment('b.jpg')])))->toBe(['b.jpg']);
});

it('takes embedded images in page order, once each', function () {
    $body = '<p>2026 - březen</p>'
        .'<ac:image ac:width="400"><ri:attachment ri:filename="z.jpg"/></ac:image>'
        .'<p>2022&nbsp;– prosinec &ndash; &bdquo;zima&ldquo;</p>'
        .'<ac:image><ri:attachment ri:filename="a.jpg"/></ac:image>'
        .'<ac:image><ri:attachment ri:filename="z.jpg"/></ac:image>';

    $analysis = analyzePage($body, [attachment('a.jpg'), attachment('z.jpg'), attachment('unused.jpg')]);

    expect(photoNames($analysis))->toBe(['z.jpg', 'a.jpg'])
        ->and($analysis->skipped)->toBe([]);
});

it('skips images of other pages, external images and missing attachments, with reasons', function () {
    $body = '<ac:image><ri:attachment ri:filename="other.jpg"><ri:page ri:content-title="Jiná stránka"/></ri:attachment></ac:image>'
        .'<ac:image><ri:url ri:value="http://169.254.169.254/latest/meta-data"/></ac:image>'
        .'<ac:image><ri:attachment ri:filename="gone.jpg"/></ac:image>';

    expect(analyzePage($body, [])->skipped)->toBe([
        ['name' => 'other.jpg', 'reason' => 'obrázek z jiné stránky'],
        ['name' => 'http://169.254.169.254/latest/meta-data', 'reason' => 'externí obrázek'],
        ['name' => 'gone.jpg', 'reason' => 'příloha nenalezena'],
    ]);
});

it('accepts images named without an extension', function () {
    $analysis = analyzePage(galleryMacro(), [attachment('Pohled směr Jih '), attachment('scan', 'image/tiff')]);

    expect(photoNames($analysis))->toBe(['Pohled směr Jih '])
        ->and($analysis->photos[0]->galleryFilename())->toBe('Pohled směr Jih.jpg')
        ->and(attachment('a.PNG', 'image/png')->galleryFilename())->toBe('a.PNG')
        ->and(attachment('photo', 'image/webp')->galleryFilename())->toBe('photo.webp')
        ->and(attachment('IMG 2012.05.10', 'image/jpeg')->galleryFilename())->toBe('IMG 2012.05.10.jpg')
        ->and($analysis->skipped)->toBe([['name' => 'scan', 'reason' => 'nepodporovaný formát']]);
});

it('skips images the gallery cannot store', function () {
    $attachments = [
        attachment('ok.jpg'),
        attachment('scan.tiff', 'image/tiff'),
        attachment('huge.jpg', size: 60 * 1024 * 1024),
    ];

    $analysis = analyzePage(galleryMacro(), $attachments);

    expect(photoNames($analysis))->toBe(['ok.jpg'])
        ->and($analysis->skipped)->toBe([
            ['name' => 'huge.jpg', 'reason' => 'soubor je větší než 50 MB'],
            ['name' => 'scan.tiff', 'reason' => 'nepodporovaný formát'],
        ]);
});

it('finds nothing on an index page and reports an unreadable body', function () {
    expect(analyzePage('<p><ac:link><ri:page ri:content-title="vyhledy_2026"/></ac:link></p>', [attachment('a.jpg')]))
        ->photos->toBe([])
        ->skipped->toBe([]);

    expect(analyzePage('<p>unclosed', [])->skipped)->toBe([['name' => 'Foto výhled', 'reason' => 'obsah stránky nelze přečíst']]);
});

it('does not resolve external entities', function () {
    $body = '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><p>&xxe;</p>';

    expect(analyzePage($body, [])->skipped[0]['reason'] ?? null)->toBe('obsah stránky nelze přečíst');
});

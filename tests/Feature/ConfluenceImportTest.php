<?php

use App\Enums\ImportItemStatus;
use App\Enums\ImportStatus;
use App\Jobs\ImportConfluencePage;
use App\Models\ConfluenceImport;
use App\Models\ConfluenceImportItem;
use App\Models\GalleryImage;
use App\Models\GalleryImageDescription;
use App\Models\User;
use App\Services\Confluence\ConfluenceException;
use App\Services\Confluence\ConfluenceImporter;
use App\Services\GalleryStorage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const IMPORT_PAGE_URL = 'https://doc.hkfree.org/spaces/fotogalerie/pages/22020482/Foto+v%C3%BDhled';

beforeEach(function () {
    fakeUserdbAreas();
    Storage::fake('local');
});

/**
 * Fake a page with the given attachments, whose downloads return $downloads[filename]
 * (a JPEG by default).
 *
 * @param  list<array<string, mixed>>  $attachments
 * @param  array<string, mixed>  $downloads
 */
function fakeImportablePage(array $attachments, array $downloads = []): void
{
    $image = UploadedFile::fake()->image('photo.jpg', 64, 48);
    $jpeg = file_get_contents($image->getRealPath());

    Http::fake(['doc.hkfree.org/download/*' => function ($request) use ($downloads, $jpeg) {
        $filename = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));

        $download = $downloads[$filename] ?? $jpeg;

        return is_string($download) ? Http::response($download) : $download;
    }]);

    fakeConfluencePage(body: '<ac:structured-macro ac:name="gallery"/>', attachments: $attachments, ancestors: ['Home', 'Brno']);
}

function startImport(): ConfluenceImport
{
    return app(ConfluenceImporter::class)->start(null, 'pub', 13, 201, 22020482);
}

it('queues an import with one item per photo', function () {
    Queue::fake();
    fakeImportablePage([confluenceAttachment('a.jpg', size: 2000), confluenceAttachment('b.jpg')]);

    $import = startImport();

    expect($import)
        ->status->toBe(ImportStatus::Queued)
        ->page_title->toBe('Foto výhled')
        ->and($import->items()->pluck('original_filename')->all())->toBe(['a.jpg', 'b.jpg'])
        ->and($import->items()->first()->status)->toBe(ImportItemStatus::Pending);

    Queue::assertPushed(ImportConfluencePage::class, fn (ImportConfluencePage $job) => $job->importId === $import->id);
});

it('downloads, stores and indexes the photos, dated by EXIF or the attachment date', function () {
    fakeImportablePage(
        [confluenceAttachment('exif.jpg', created: '2011-05-25T21:49:54.000+02:00'), confluenceAttachment('plain.jpg', created: '2011-05-25T21:49:54.000+02:00')],
        ['exif.jpg' => jpegWithExif('2010:07:01 12:00:00')],
    );

    $import = startImport();

    expect($import->fresh()->status)->toBe(ImportStatus::Done)
        ->and($import->itemCounts())->toMatchArray(['imported' => 2, 'failed' => 0, 'pending' => 0]);

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/exif.jpg');
    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/thumbs/plain.jpg');

    expect(GalleryImage::where('filename', 'exif.jpg')->sole()->sort_at->format('Y-m-d H:i'))->toBe('2010-07-01 12:00')
        ->and(GalleryImage::where('filename', 'plain.jpg')->sole()->sort_at->format('Y-m-d H:i'))->toBe('2011-05-25 21:49')
        ->and(Storage::disk('local')->files('gallery/tmp'))->toBe([]);
});

it('stores photos named without an extension under a proper name, with their direction', function () {
    fakeImportablePage([confluenceAttachment('Pohled směr Jih ')]);

    startImport();

    Storage::disk('local')->assertExists('gallery/ap/13/201/pub/Pohled směr Jih.jpg');
    expect(GalleryImageDescription::sole())->heading->toBe(180)->heading_source->toBe('filename');
});

it('records failed photos with a reason and goes on', function () {
    fakeImportablePage(
        [confluenceAttachment('broken.jpg'), confluenceAttachment('missing.jpg'), confluenceAttachment('fine.jpg')],
        ['broken.jpg' => Http::response('not an image'), 'missing.jpg' => Http::response('', 500)],
    );

    $import = startImport();

    expect($import->items()->orderBy('id')->get()->map->only(['original_filename', 'status', 'reason'])->all())->toBe([
        ['original_filename' => 'broken.jpg', 'status' => ImportItemStatus::Failed, 'reason' => 'Soubor není platný obrázek.'],
        ['original_filename' => 'fine.jpg', 'status' => ImportItemStatus::Imported, 'reason' => null],
        ['original_filename' => 'missing.jpg', 'status' => ImportItemStatus::Failed, 'reason' => 'Stažení přílohy se nezdařilo (chyba 500).'],
    ])->and($import->fresh()->status)->toBe(ImportStatus::Done);

    Storage::disk('local')->assertMissing('gallery/ap/13/201/pub/broken.jpg');
});

it('rejects downloads larger than the upload limit', function () {
    fakeImportablePage([confluenceAttachment('big.jpg')], ['big.jpg' => Http::response(str_repeat('x', GalleryStorage::MAX_UPLOAD_KB * 1024 + 1))]);

    $import = startImport();

    expect($import->items()->sole()->reason)->toBe('Soubor je větší než povolený limit.');
});

it('skips photos already imported into this gallery and imports new versions', function () {
    Queue::fake();
    fakeImportablePage([
        confluenceAttachment('old.jpg', id: 1),
        confluenceAttachment('updated.jpg', id: 2),
        confluenceAttachment('new.jpg', id: 3),
    ]);

    $previous = ConfluenceImport::factory()->done()->create();
    ConfluenceImportItem::factory()->imported()->for($previous, 'import')->create(['attachment_id' => 1, 'attachment_version' => 1]);
    ConfluenceImportItem::factory()->imported()->for($previous, 'import')->create(['attachment_id' => 2, 'attachment_version' => 0]);
    $otherGallery = ConfluenceImport::factory()->done()->create(['ap_id' => 202]);
    ConfluenceImportItem::factory()->imported()->for($otherGallery, 'import')->create(['attachment_id' => 3, 'attachment_version' => 1]);

    $import = startImport();

    expect($import->items()->orderBy('id')->get()->map->only(['original_filename', 'status'])->all())->toBe([
        ['original_filename' => 'new.jpg', 'status' => ImportItemStatus::Pending],
        ['original_filename' => 'old.jpg', 'status' => ImportItemStatus::Skipped],
        ['original_filename' => 'updated.jpg', 'status' => ImportItemStatus::Pending],
    ]);
});

it('refuses to start when nothing is new, an import is running, or disk space is short', function () {
    Queue::fake();
    fakeImportablePage([confluenceAttachment('a.jpg', id: 1, size: 5000)]);

    $running = ConfluenceImport::factory()->create();
    expect(fn () => startImport())->toThrow(ConfluenceException::class, 'už probíhá import');
    $running->update(['status' => ImportStatus::Done]);

    $this->partialMock(GalleryStorage::class, fn ($mock) => $mock->shouldReceive('freeSpace')->andReturn(1024));
    expect(fn () => startImport())->toThrow(ConfluenceException::class, 'Na serveru není dost místa');

    ConfluenceImportItem::factory()->imported()->for($running, 'import')->create(['attachment_id' => 1, 'attachment_version' => 1]);
    expect(fn () => startImport())->toThrow(ConfluenceException::class, 'žádné nové fotky');

    expect(ConfluenceImport::count())->toBe(1);
});

it('stops when its time budget is used up and continues in the next run', function () {
    Queue::fake();
    fakeImportablePage([confluenceAttachment('a.jpg'), confluenceAttachment('b.jpg')]);
    $import = startImport();
    $importer = app(ConfluenceImporter::class);

    expect($importer->process($import, seconds: 0))->toBeFalse()
        ->and($import->itemCounts()['pending'])->toBe(2)
        ->and($import->fresh()->status)->toBe(ImportStatus::Running)
        ->and($importer->process($import))->toBeTrue()
        ->and($import->itemCounts()['imported'])->toBe(2);
});

it('requeues itself until the import is done', function () {
    Queue::fake();
    $import = ConfluenceImport::factory()->create();

    $this->mock(ConfluenceImporter::class)->shouldReceive('process')->once()->andReturn(false);
    (new ImportConfluencePage($import->id))->handle(app(ConfluenceImporter::class));

    Queue::assertPushed(ImportConfluencePage::class, 1);
});

it('indexes an already stored photo after a crash instead of downloading it again', function () {
    Queue::fake();
    fakeImportablePage([confluenceAttachment('a.jpg')]);
    $import = startImport();
    $item = $import->items()->sole();

    Storage::disk('local')->put('gallery/ap/13/201/pub/a.jpg', 'stored before the crash');
    $item->update(['stored_filename' => 'a.jpg']);

    app(ConfluenceImporter::class)->process($import);

    expect($item->fresh()->status)->toBe(ImportItemStatus::Imported)
        ->and(Storage::disk('local')->files('gallery/ap/13/201/pub'))->toBe(['gallery/ap/13/201/pub/a.jpg'])
        ->and(GalleryImage::where('filename', 'a.jpg')->exists())->toBeTrue();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/download/'));
});

it('marks the import failed when the job gives up', function () {
    $import = ConfluenceImport::factory()->create();

    (new ImportConfluencePage($import->id))->failed(new RuntimeException('boom'));

    expect($import->fresh())->status->toBe(ImportStatus::Failed)->error->toContain('Import se nezdařil');
});

it('starts from the preview and shows progress to managers', function () {
    Queue::fake();
    fakeImportablePage([confluenceAttachment('a.jpg')]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('confluence.import.create', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'url' => IMPORT_PAGE_URL]))
        ->assertSee('Importovat 1 fotku');

    $response = $this->actingAs($admin)->post(route('confluence.import.store', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), ['url' => IMPORT_PAGE_URL]);

    $import = ConfluenceImport::sole();
    $response->assertRedirect(route('confluence.import.show', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'import' => $import]));

    expect($import->user_id)->toBe($admin->id);

    $this->actingAs($admin)->get(route('confluence.import.show', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'import' => $import]))
        ->assertSee('Čeká na spuštění')
        ->assertSee('http-equiv="refresh"', escape: false);
});

it('shows the result with failures and stops refreshing when done', function () {
    $import = ConfluenceImport::factory()->done()->create();
    ConfluenceImportItem::factory()->imported()->for($import, 'import')->create();
    ConfluenceImportItem::factory()->for($import, 'import')->create(['original_filename' => 'bad.jpg', 'status' => ImportItemStatus::Failed, 'reason' => 'Soubor není platný obrázek.']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('confluence.import.show', ['visibility' => 'pub', 'area' => 13, 'ap' => 201, 'import' => $import]))
        ->assertSee('Hotovo, s chybami.')
        ->assertSee('bad.jpg')
        ->assertSee('Zobrazit na časové ose')
        ->assertDontSee('http-equiv="refresh"', escape: false);
});

it('shows an import only in its own gallery and only to managers', function () {
    $import = ConfluenceImport::factory()->create(['ap_id' => 202]);
    $url = fn (int $ap) => route('confluence.import.show', ['visibility' => 'pub', 'area' => 13, 'ap' => $ap, 'import' => $import]);

    $this->actingAs(User::factory()->create())->get($url(202))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get($url(201))->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get($url(202))->assertSuccessful();
});

it('returns to the preview with the reason when an import cannot start', function () {
    fakeImportablePage([confluenceAttachment('a.jpg')]);
    ConfluenceImport::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('confluence.import.store', ['visibility' => 'pub', 'area' => 13, 'ap' => 201]), ['url' => IMPORT_PAGE_URL])
        ->assertRedirect()
        ->assertSessionHas('error', 'Do této galerie už probíhá import. Počkejte, až doběhne.');
});

it('runs the queue worker from the scheduler', function () {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    expect($commands)->toContain('queue:work --stop-when-empty');
});

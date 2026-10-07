<?php

namespace App\Services\Confluence;

use App\Enums\ImportItemStatus;
use App\Enums\ImportStatus;
use App\Jobs\ImportConfluencePage;
use App\Models\ConfluenceImport;
use App\Models\ConfluenceImportItem;
use App\Models\User;
use App\Services\GalleryIndex;
use App\Services\GalleryStorage;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use RuntimeException;

/**
 * Imports the photos of a Confluence page into a gallery.
 *
 * Starting an import records one item per photo and queues {@see ImportConfluencePage}, which
 * calls {@see self::process()} in time-boxed chunks. Each item's progress is saved as it goes
 * (the stored file name right after storing), so a crashed or retried job neither loses nor
 * duplicates photos.
 */
class ConfluenceImporter
{
    /**
     * How long one job run starts new photos before handing the rest to a fresh job. A photo
     * started just before this may still take {@see self::DOWNLOAD_SECONDS} plus storing, which
     * must stay below the job timeout ({@see ImportConfluencePage::$timeout}).
     */
    public const CHUNK_SECONDS = 20;

    /** Longest a single attachment download may take. */
    public const DOWNLOAD_SECONDS = 50;

    /** A photo whose processing was interrupted this many times (job killed) is given up. */
    private const MAX_ATTEMPTS = 2;

    /** Free disk space that must remain after an import. */
    private const DISK_RESERVE_BYTES = 1024 ** 3;

    public function __construct(
        private readonly ConfluenceClient $confluence,
        private readonly PageAnalyzer $analyzer,
        private readonly GalleryStorage $storage,
        private readonly GalleryIndex $index,
    ) {}

    /**
     * Attachments (as "id:version") that were already imported into this gallery.
     *
     * @param  list<ConfluenceAttachment>  $attachments
     * @return array<string, true>
     */
    public function alreadyImported(string $visibility, int $areaId, int $apId, array $attachments): array
    {
        if ($attachments === []) {
            return [];
        }

        return ConfluenceImportItem::query()
            ->where('status', ImportItemStatus::Imported)
            ->whereIn('attachment_id', array_map(fn (ConfluenceAttachment $attachment): int => $attachment->id, $attachments))
            ->whereHas('import', fn ($query) => $query->forGallery($visibility, $areaId, $apId))
            ->get(['attachment_id', 'attachment_version'])
            ->mapWithKeys(fn (ConfluenceImportItem $item): array => ["{$item->attachment_id}:{$item->attachment_version}" => true])
            ->all();
    }

    /**
     * Read the page and queue the import of its photos that this gallery doesn't have yet.
     *
     * @throws ConfluenceException when the import can't start; the message is shown to the user
     */
    public function start(?User $user, string $visibility, int $areaId, int $apId, int $pageId): ConfluenceImport
    {
        // One start at a time per gallery, so a double submit can't queue the same photos twice.
        $lock = Cache::lock("confluence-import:{$visibility}:{$areaId}:{$apId}", 30);

        if (! $lock->get()) {
            throw new ConfluenceException('Do této galerie už probíhá import. Počkejte, až doběhne.');
        }

        try {
            return $this->queue($user, $visibility, $areaId, $apId, $pageId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ConfluenceException
     */
    private function queue(?User $user, string $visibility, int $areaId, int $apId, int $pageId): ConfluenceImport
    {
        $running = ConfluenceImport::query()
            ->forGallery($visibility, $areaId, $apId)
            ->whereIn('status', [ImportStatus::Queued, ImportStatus::Running])
            ->exists();

        if ($running) {
            throw new ConfluenceException('Do této galerie už probíhá import. Počkejte, až doběhne.');
        }

        $page = $this->confluence->page($pageId);
        $photos = $this->analyzer->analyze($page, $this->confluence->attachments($pageId))->photos;
        $imported = $this->alreadyImported($visibility, $areaId, $apId, $photos);
        $new = array_values(array_filter($photos, fn (ConfluenceAttachment $photo): bool => ! isset($imported[$this->key($photo)])));

        if ($new === []) {
            throw new ConfluenceException('Na stránce nejsou žádné nové fotky k importu.');
        }

        $needed = array_sum(array_map(fn (ConfluenceAttachment $photo): int => $photo->size, $new)) + self::DISK_RESERVE_BYTES;
        $free = $this->storage->freeSpace();

        if ($free < $needed) {
            throw new ConfluenceException(sprintf(
                'Na serveru není dost místa: import potřebuje %s, volných je %s.',
                Number::fileSize($needed, precision: 1), Number::fileSize($free, precision: 1),
            ));
        }

        $import = DB::transaction(function () use ($user, $visibility, $areaId, $apId, $page, $photos, $imported): ConfluenceImport {
            $import = ConfluenceImport::create([
                'user_id' => $user?->id,
                'area_id' => $areaId,
                'ap_id' => $apId,
                'visibility' => $visibility,
                'page_id' => $page->id,
                'page_title' => $page->title,
                'page_version' => $page->version,
                'page_url' => $page->url,
                'status' => ImportStatus::Queued,
            ]);

            $import->items()->createMany(array_map(fn (ConfluenceAttachment $photo): array => [
                'attachment_id' => $photo->id,
                'attachment_version' => $photo->version,
                'original_filename' => $photo->galleryFilename(),
                'size' => $photo->size,
                'download_path' => $photo->downloadPath,
                'attachment_created_at' => $photo->createdAt->utc(),
                'status' => isset($imported[$this->key($photo)]) ? ImportItemStatus::Skipped : ImportItemStatus::Pending,
                'reason' => isset($imported[$this->key($photo)]) ? 'již importováno' : null,
            ], $photos));

            return $import;
        });

        ImportConfluencePage::dispatch($import->id);

        return $import;
    }

    /**
     * Import pending items until the time budget is used up.
     *
     * @return bool whether the import is finished
     */
    public function process(ConfluenceImport $import, float $seconds = self::CHUNK_SECONDS): bool
    {
        $import->update(['status' => ImportStatus::Running]);
        $deadline = microtime(true) + $seconds;

        foreach ($import->items()->where('status', ImportItemStatus::Pending)->lazyById() as $item) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            $this->importItem($import, $item);
        }

        $import->update(['status' => ImportStatus::Done]);

        return true;
    }

    /**
     * Download, store and index one photo; a failure is recorded on the item and the import
     * goes on with the next one.
     */
    private function importItem(ConfluenceImport $import, ConfluenceImportItem $item): void
    {
        // Count the attempt before working, so a photo that keeps getting the job killed (too
        // slow, too heavy) is given up instead of failing the whole import.
        if ($item->attempts >= self::MAX_ATTEMPTS) {
            $item->update(['status' => ImportItemStatus::Failed, 'reason' => 'Zpracování opakovaně nedoběhlo (příliš pomalé stažení nebo příliš velký soubor).']);

            return;
        }

        $item->increment('attempts');

        if ($item->stored_filename !== null && $this->storage->exists($import->visibility, $import->area_id, $import->ap_id, $item->stored_filename)) {
            $this->complete($import, $item);

            return;
        }

        $temporary = $this->storage->temporaryPath("confluence-{$import->id}-{$item->id}.part");

        try {
            $this->confluence->download($item->download_path, $temporary, GalleryStorage::MAX_UPLOAD_KB * 1024, self::DOWNLOAD_SECONDS);

            if (@getimagesize($temporary) === false) {
                throw new ConfluenceException('Soubor není platný obrázek.');
            }

            $filename = $this->storage->storeImage($import->visibility, $import->area_id, $import->ap_id, new File($temporary), $item->original_filename);
            $item->update(['stored_filename' => $filename]);

            $this->complete($import, $item);
        } catch (RuntimeException $exception) {
            $item->update([
                'status' => ImportItemStatus::Failed,
                'reason' => $exception instanceof ConfluenceException ? $exception->getMessage() : 'Uložení souboru se nezdařilo.',
            ]);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Index a stored photo, dated by EXIF or else by its attachment date, and mark it imported.
     * An indexing failure is reported but doesn't fail the photo: reconciling indexes it later.
     */
    private function complete(ConfluenceImport $import, ConfluenceImportItem $item): void
    {
        rescue(fn () => $this->index->record(
            $import->visibility, $import->area_id, $import->ap_id, $item->stored_filename, $item->attachment_created_at,
        ));

        $item->update(['status' => ImportItemStatus::Imported]);
    }

    private function key(ConfluenceAttachment $attachment): string
    {
        return "{$attachment->id}:{$attachment->version}";
    }
}

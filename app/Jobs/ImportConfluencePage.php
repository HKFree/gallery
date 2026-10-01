<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ConfluenceImport;
use App\Services\Confluence\ConfluenceImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Imports a Confluence page's photos in time-boxed chunks: each run works for about
 * {@see ConfluenceImporter::CHUNK_SECONDS} seconds and queues itself again for the rest.
 * The timeout stays below the database queue's `retry_after` (90 s), so a run is never
 * started twice in parallel.
 */
class ImportConfluencePage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 3;

    public function __construct(public int $importId) {}

    /**
     * Execute the job.
     */
    public function handle(ConfluenceImporter $importer): void
    {
        $import = ConfluenceImport::find($this->importId);

        if ($import === null || ! $import->status->isActive()) {
            return;
        }

        if (! $importer->process($import)) {
            self::dispatch($this->importId);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        ConfluenceImport::whereKey($this->importId)->update([
            'status' => ImportStatus::Failed,
            'error' => 'Import se nezdařil. Zkuste ho spustit znovu; už naimportované fotky se přeskočí.',
        ]);
    }
}

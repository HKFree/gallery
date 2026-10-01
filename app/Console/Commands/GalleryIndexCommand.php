<?php

namespace App\Console\Commands;

use App\Services\GalleryIndex;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gallery:index
    {--area= : Only reconcile galleries of this area id}
    {--ap= : Only reconcile galleries of this AP id}
    {--dry-run : Report what would change, including the month distribution, without writing}')]
#[Description('Sync the timeline index with the gallery images on disk')]
class GalleryIndexCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GalleryIndex $index): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $index->reconcileAll(
            areaId: $this->option('area') === null ? null : (int) $this->option('area'),
            apId: $this->option('ap') === null ? null : (int) $this->option('ap'),
            dryRun: $dryRun,
        );

        $this->components->info($dryRun ? 'Dry run, nothing was written.' : 'Gallery index synced.');

        $this->table(['', 'images'], [
            [$dryRun ? 'would add' : 'added', $result['added']],
            [$dryRun ? 'would remove' : 'removed', $result['removed']],
            ['added with an EXIF taken date', $result['taken']],
            ['added dated by file time', $result['added'] - $result['taken']],
        ]);

        if ($dryRun && $result['months'] !== []) {
            $this->newLine();
            $this->line('Month distribution of images to add (check for one month holding most of them):');
            $this->table(['month', 'images'], collect($result['months'])->map(fn (int $count, string $month): array => [$month, $count])->values()->all());
        }

        return self::SUCCESS;
    }
}

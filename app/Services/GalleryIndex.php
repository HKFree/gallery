<?php

namespace App\Services;

use App\Models\GalleryImage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the `gallery_images` index in sync with the images on disk.
 *
 * The disk is the source of truth for which images exist; the index stores their timeline
 * dates. Uploads and deletions update it directly, and reconciling repairs any drift (files
 * copied in or restored from the trash, rows whose file is gone).
 *
 * @phpstan-type ReconcileResult array{added: int, removed: int, pending: int, taken: int, months: array<string, int>}
 */
class GalleryIndex
{
    public function __construct(
        private readonly GalleryStorage $storage,
        private readonly ImageDate $dates,
    ) {}

    /**
     * Index (or re-index) a stored image, reading its dates from EXIF and the file.
     *
     * @param  CarbonInterface|null  $sourceDate  a date reported by the image's source (the
     *                                            browser's `File.lastModified`, a Confluence
     *                                            attachment date), used when EXIF has none
     */
    public function record(string $visibility, int $areaId, int $apId, string $filename, ?CarbonInterface $sourceDate = null): GalleryImage
    {
        return GalleryImage::updateOrCreate(
            $this->key($visibility, $areaId, $apId, $filename),
            $this->dateAttributes($visibility, $areaId, $apId, $filename, $sourceDate),
        );
    }

    /**
     * Remove an image from the index.
     */
    public function forget(string $visibility, int $areaId, int $apId, string $filename): void
    {
        GalleryImage::query()->where($this->key($visibility, $areaId, $apId, $filename))->delete();
    }

    /**
     * Bring one gallery's index in line with its directory: index files missing from it
     * (at most $limit of them, the rest is reported as pending) and drop rows whose file is gone.
     *
     * @return ReconcileResult
     */
    public function reconcileAp(string $visibility, int $areaId, int $apId, ?int $limit = null, bool $dryRun = false): array
    {
        $onDisk = $this->storage->imageNames($visibility, $areaId, $apId);
        $indexed = $this->galleryQuery($visibility, $areaId, $apId)->pluck('filename')->all();

        $missing = array_values(array_diff($onDisk, $indexed));
        $stale = array_values(array_diff($indexed, $onDisk));
        $toAdd = $limit === null ? $missing : array_slice($missing, 0, $limit);

        $result = ['added' => count($toAdd), 'removed' => count($stale), 'pending' => count($missing) - count($toAdd), 'taken' => 0, 'months' => []];

        foreach ($toAdd as $filename) {
            $image = $dryRun
                ? $this->makeImage($visibility, $areaId, $apId, $filename)
                : $this->record($visibility, $areaId, $apId, $filename);

            $result['taken'] += $image->taken_at === null ? 0 : 1;
            $result['months'][$image->sort_month] = ($result['months'][$image->sort_month] ?? 0) + 1;
        }

        if (! $dryRun && $stale !== []) {
            $this->galleryQuery($visibility, $areaId, $apId)->whereIn('filename', $stale)->delete();
        }

        return $result;
    }

    /**
     * Reconcile every gallery found on disk or in the index, optionally limited to one area/AP.
     *
     * @return ReconcileResult
     */
    public function reconcileAll(?int $areaId = null, ?int $apId = null, bool $dryRun = false): array
    {
        $indexed = GalleryImage::query()
            ->select(['visibility', 'area_id', 'ap_id'])
            ->distinct()
            ->get()
            ->map(fn (GalleryImage $image): array => ['visibility' => $image->visibility, 'area' => $image->area_id, 'ap' => $image->ap_id])
            ->all();

        $galleries = collect([...$this->storage->galleryDirectories(), ...$indexed])
            ->unique(fn (array $gallery): string => "{$gallery['visibility']}/{$gallery['area']}/{$gallery['ap']}")
            ->filter(fn (array $gallery): bool => ($areaId === null || $gallery['area'] === $areaId) && ($apId === null || $gallery['ap'] === $apId));

        $total = ['added' => 0, 'removed' => 0, 'pending' => 0, 'taken' => 0, 'months' => []];

        foreach ($galleries as $gallery) {
            $result = $this->reconcileAp($gallery['visibility'], $gallery['area'], $gallery['ap'], dryRun: $dryRun);

            foreach (['added', 'removed', 'pending', 'taken'] as $counter) {
                $total[$counter] += $result[$counter];
            }

            foreach ($result['months'] as $month => $count) {
                $total['months'][$month] = ($total['months'][$month] ?? 0) + $count;
            }
        }

        ksort($total['months']);

        return $total;
    }

    /**
     * An unsaved index entry for a stored image, with its sort date derived (for dry runs).
     */
    private function makeImage(string $visibility, int $areaId, int $apId, string $filename): GalleryImage
    {
        $image = new GalleryImage([
            ...$this->key($visibility, $areaId, $apId, $filename),
            ...$this->dateAttributes($visibility, $areaId, $apId, $filename),
        ]);

        $image->syncSortDate();

        return $image;
    }

    /**
     * @return array{taken_at: CarbonImmutable|null, client_modified_at: CarbonImmutable|null, uploaded_at: CarbonImmutable}
     */
    private function dateAttributes(string $visibility, int $areaId, int $apId, string $filename, ?CarbonInterface $sourceDate = null): array
    {
        return [
            'taken_at' => $this->dates->takenAt($this->storage->absolutePath($visibility, $areaId, $apId, $filename)),
            'client_modified_at' => $this->dates->fromSourceDate($sourceDate),
            'uploaded_at' => $this->dates->fromTimestamp($this->storage->lastModified($visibility, $areaId, $apId, $filename)),
        ];
    }

    /**
     * @return array{visibility: string, area_id: int, ap_id: int, filename: string}
     */
    private function key(string $visibility, int $areaId, int $apId, string $filename): array
    {
        return ['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId, 'filename' => basename($filename)];
    }

    /**
     * @return Builder<GalleryImage>
     */
    private function galleryQuery(string $visibility, int $areaId, int $apId): Builder
    {
        return GalleryImage::query()->where(['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId]);
    }
}

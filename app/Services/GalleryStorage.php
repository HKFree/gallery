<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\FileExtensionEncoder;
use Intervention\Image\Laravel\Facades\Image;
use RuntimeException;
use SplFileInfo;

/**
 * Stores, lists and soft-deletes gallery images for a given AP.
 *
 * All files live on the private `local` disk and are streamed through a
 * controller; visibility (`pub`/`priv`) is the last path segment of an AP's
 * directory. Thumbnails are generated on upload into a `thumbs/` subdirectory.
 * Deletion is soft: files are renamed with the {@see self::TRASH_PREFIX} and hidden.
 */
class GalleryStorage
{
    private const DISK = 'local';

    private const TRASH_PREFIX = '_trashed_';

    private const THUMBNAIL_WIDTH = 400;

    /** Temp directory (within the disk) holding in-progress chunked uploads. */
    private const TMP_DIR = 'gallery/tmp';

    /** Maximum size of an assembled upload, in kilobytes (50 MB). */
    private const MAX_UPLOAD_KB = 51200;

    /** Orphaned chunk files older than this (hours) are pruned opportunistically. */
    private const STALE_AFTER_HOURS = 6;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The directory holding an AP's images for the given visibility.
     */
    public function directory(string $visibility, int $areaId, int $apId): string
    {
        return "gallery/ap/{$areaId}/{$apId}/{$visibility}";
    }

    /**
     * Relative path (within the disk) to an image or its thumbnail.
     */
    public function path(string $visibility, int $areaId, int $apId, string $filename, bool $thumb = false): string
    {
        $dir = $this->directory($visibility, $areaId, $apId);
        $dir = $thumb ? "{$dir}/thumbs" : $dir;

        return "{$dir}/".basename($filename);
    }

    /**
     * Whether the image (or its thumbnail) exists on disk.
     */
    public function exists(string $visibility, int $areaId, int $apId, string $filename, bool $thumb = false): bool
    {
        return $this->disk()->exists($this->path($visibility, $areaId, $apId, $filename, $thumb));
    }

    /**
     * Image filenames for an AP, sorted by name, excluding trashed files.
     *
     * @return list<string>
     */
    public function imageNames(string $visibility, int $areaId, int $apId): array
    {
        return collect($this->disk()->files($this->directory($visibility, $areaId, $apId)))
            ->map(fn (string $path): string => basename($path))
            ->reject(fn (string $name): bool => str_starts_with($name, self::TRASH_PREFIX))
            ->filter(fn (string $name): bool => $this->isImage($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Append one chunk of a chunked upload to its temp file.
     *
     * Chunks must arrive in order: index 0 starts a fresh temp file (discarding any
     * stale leftover with the same id), later indexes require the temp file to exist.
     * The running size is capped to keep parity with {@see self::MAX_UPLOAD_KB}.
     */
    public function appendChunk(string $uploadId, UploadedFile $chunk, int $chunkIndex): void
    {
        $this->pruneStaleUploads();

        $disk = $this->disk();
        $relative = $this->tmpPath($uploadId);
        $disk->makeDirectory(self::TMP_DIR);
        $absolute = $disk->path($relative);

        if ($chunkIndex === 0) {
            $disk->delete($relative);
        } elseif (! $disk->exists($relative)) {
            throw new RuntimeException('Chunk out of order or upload session expired.');
        }

        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($absolute, 'ab');

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        if (filesize($absolute) > self::MAX_UPLOAD_KB * 1024) {
            $disk->delete($relative);

            throw new RuntimeException('Soubor je příliš velký (maximálně 50 MB).');
        }
    }

    /**
     * Validate a fully-uploaded temp file as an image, store it (with thumbnail) and
     * discard the temp file. Throws and cleans up if the assembled file is not an image.
     *
     * @return string the stored filename
     */
    public function assembleUpload(string $visibility, int $areaId, int $apId, string $uploadId, string $originalName): string
    {
        $disk = $this->disk();
        $relative = $this->tmpPath($uploadId);
        $absolute = $disk->path($relative);

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! $disk->exists($relative) || getimagesize($absolute) === false || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $disk->delete($relative);

            throw new RuntimeException('Soubor není platný obrázek.');
        }

        try {
            $filename = $this->persistImage($visibility, $areaId, $apId, new File($absolute), $originalName);
        } finally {
            $disk->delete($relative);
        }

        return $filename;
    }

    /**
     * Store an image file under an AP's directory and generate its thumbnail.
     *
     * @return string the stored filename
     */
    private function persistImage(string $visibility, int $areaId, int $apId, SplFileInfo $file, string $originalName): string
    {
        $disk = $this->disk();
        $dir = $this->directory($visibility, $areaId, $apId);

        $filename = $this->uniqueFilename($visibility, $areaId, $apId, $originalName);
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'jpg';

        if ($disk->putFileAs($dir, $file, $filename) === false) {
            Log::error('Gallery: failed to store uploaded image', [
                'visibility' => $visibility, 'area' => $areaId, 'ap' => $apId, 'filename' => $filename,
            ]);

            throw new RuntimeException("Failed to store uploaded image [{$filename}].");
        }

        // A thumbnail failure must not lose the already-stored original; log and move on.
        try {
            $thumbnail = Image::decode($file->getRealPath())
                ->scaleDown(width: self::THUMBNAIL_WIDTH)
                ->encode(new FileExtensionEncoder($extension, quality: 80));

            $disk->put("{$dir}/thumbs/{$filename}", (string) $thumbnail);
        } catch (\Throwable $e) {
            Log::error('Gallery: failed to generate thumbnail', [
                'visibility' => $visibility, 'area' => $areaId, 'ap' => $apId, 'filename' => $filename,
                'exception' => $e->getMessage(),
            ]);
        }

        return $filename;
    }

    /**
     * Soft-delete an image: rename it (and its thumbnail) into the trash.
     */
    public function trash(string $visibility, int $areaId, int $apId, string $filename): bool
    {
        $disk = $this->disk();
        $dir = $this->directory($visibility, $areaId, $apId);
        $filename = basename($filename);

        $source = "{$dir}/{$filename}";

        if (str_starts_with($filename, self::TRASH_PREFIX) || ! $disk->exists($source)) {
            return false;
        }

        $trashName = self::TRASH_PREFIX.now()->format('YmdHis').'_'.$filename;
        $disk->move($source, "{$dir}/{$trashName}");

        $thumb = "{$dir}/thumbs/{$filename}";

        if ($disk->exists($thumb)) {
            $disk->move($thumb, "{$dir}/thumbs/{$trashName}");
        }

        return true;
    }

    /**
     * Build a collision-free, traversal-safe filename for an upload.
     */
    private function uniqueFilename(string $visibility, int $areaId, int $apId, string $originalName): string
    {
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'jpg';

        $base = preg_replace('/[^\p{L}\p{N}\-_. ]+/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $base = trim((string) $base) ?: 'image';

        if (str_starts_with($base, self::TRASH_PREFIX)) {
            $base = 'file-'.$base;
        }

        $disk = $this->disk();
        $dir = $this->directory($visibility, $areaId, $apId);

        $candidate = "{$base}.{$extension}";
        $counter = 1;

        while ($disk->exists("{$dir}/{$candidate}")) {
            $candidate = "{$base}-{$counter}.{$extension}";
            $counter++;
        }

        return $candidate;
    }

    /**
     * Whether the filename belongs to a soft-deleted (trashed) image.
     */
    public function isTrashed(string $filename): bool
    {
        return str_starts_with(basename($filename), self::TRASH_PREFIX);
    }

    private function isImage(string $filename): bool
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Relative path of a chunked upload's temp file, keyed by a traversal-safe id.
     */
    private function tmpPath(string $uploadId): string
    {
        if (! Str::isUuid($uploadId)) {
            throw new RuntimeException('Invalid upload id.');
        }

        return self::TMP_DIR.'/'.basename($uploadId).'.part';
    }

    /**
     * Occasionally delete orphaned chunk files left behind by interrupted uploads.
     * Runs on a lottery so it costs nothing on the vast majority of requests.
     */
    private function pruneStaleUploads(): void
    {
        Lottery::odds(1, 50)->winner(function (): void {
            $disk = $this->disk();
            $cutoff = now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();

            foreach ($disk->files(self::TMP_DIR) as $path) {
                if (str_ends_with($path, '.part') && $disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                }
            }
        })->choose();
    }

    /**
     * The private disk backing all gallery files.
     */
    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}

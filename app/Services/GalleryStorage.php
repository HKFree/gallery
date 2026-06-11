<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\FileExtensionEncoder;
use Intervention\Image\Laravel\Facades\Image;
use RuntimeException;

/**
 * Stores, lists and soft-deletes gallery images for a given AP.
 *
 * Public images live on the `public` disk (served directly); private images
 * live on the `local` disk (streamed through an authenticated controller).
 * Thumbnails are generated on upload into a `thumbs/` subdirectory. Deletion
 * is soft: files are renamed with the {@see self::TRASH_PREFIX} and hidden.
 */
class GalleryStorage
{
    private const TRASH_PREFIX = '_trashed_';

    private const THUMBNAIL_WIDTH = 400;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The storage disk backing the given visibility.
     */
    public function diskName(string $visibility): string
    {
        return $visibility === 'priv' ? 'local' : 'public';
    }

    /**
     * The directory holding an AP's images on its disk.
     */
    public function directory(string $visibility, int $areaId, int $apId): string
    {
        $base = $visibility === 'priv' ? 'gallery-private' : 'gallery';

        return "{$base}/ap/{$areaId}/{$apId}";
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
        return Storage::disk($this->diskName($visibility))
            ->exists($this->path($visibility, $areaId, $apId, $filename, $thumb));
    }

    /**
     * Image filenames for an AP, sorted by name, excluding trashed files.
     *
     * @return list<string>
     */
    public function imageNames(string $visibility, int $areaId, int $apId): array
    {
        $disk = Storage::disk($this->diskName($visibility));

        return collect($disk->files($this->directory($visibility, $areaId, $apId)))
            ->map(fn (string $path): string => basename($path))
            ->reject(fn (string $name): bool => str_starts_with($name, self::TRASH_PREFIX))
            ->filter(fn (string $name): bool => $this->isImage($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Store an uploaded image and generate its thumbnail.
     *
     * @return string the stored filename
     */
    public function store(string $visibility, int $areaId, int $apId, UploadedFile $file): string
    {
        $disk = Storage::disk($this->diskName($visibility));
        $dir = $this->directory($visibility, $areaId, $apId);

        $filename = $this->uniqueFilename($visibility, $areaId, $apId, $file->getClientOriginalName());
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
        $disk = Storage::disk($this->diskName($visibility));
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

        $disk = Storage::disk($this->diskName($visibility));
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
}

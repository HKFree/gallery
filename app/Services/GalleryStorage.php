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

    /**
     * Estimated bytes needed per pixel to decode and scale an image (32-bit bitmap plus
     * working overhead). Used to avoid fatal out-of-memory errors on huge images.
     */
    private const DECODE_BYTES_PER_PIXEL = 8;

    /** Upper bound (bytes) the memory limit may be raised to for thumbnail generation (512 MB). */
    private const MAX_THUMBNAIL_MEMORY = 512 * 1024 * 1024;

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
     * Chunks must arrive strictly in order: index 0 starts a fresh temp file (discarding
     * any stale leftover with the same id), every later index must be the next one expected
     * according to the upload's progress file, so duplicated or skipped chunks are rejected.
     * The running size is capped to keep parity with {@see self::MAX_UPLOAD_KB}.
     */
    public function appendChunk(string $uploadId, UploadedFile $chunk, int $chunkIndex): void
    {
        $this->pruneStaleUploads();

        $disk = $this->disk();
        $relative = $this->tmpPath($uploadId);
        $progress = $this->progressPath($uploadId);
        $disk->makeDirectory(self::TMP_DIR);
        $absolute = $disk->path($relative);

        if ($chunkIndex === 0) {
            $disk->delete([$relative, $progress]);
        } elseif (! $disk->exists($relative) || $this->receivedChunks($uploadId) !== $chunkIndex) {
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
            $disk->delete([$relative, $progress]);

            throw new RuntimeException('Soubor je příliš velký (maximálně 50 MB).');
        }

        $disk->put($progress, (string) ($chunkIndex + 1));
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
        $progress = $this->progressPath($uploadId);
        $absolute = $disk->path($relative);

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! $disk->exists($relative) || getimagesize($absolute) === false || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $disk->delete([$relative, $progress]);

            throw new RuntimeException('Soubor není platný obrázek.');
        }

        try {
            $filename = $this->persistImage($visibility, $areaId, $apId, new File($absolute), $originalName);
        } finally {
            $disk->delete([$relative, $progress]);
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

        $this->generateThumbnail($file, "{$dir}/thumbs/{$filename}", $extension, [
            'visibility' => $visibility, 'area' => $areaId, 'ap' => $apId, 'filename' => $filename,
        ]);

        return $filename;
    }

    /**
     * Ensure an image has a thumbnail, generating it on demand when it is missing (e.g. a
     * previous generation failed). Returns false when no thumbnail exists or can be made.
     */
    public function ensureThumbnail(string $visibility, int $areaId, int $apId, string $filename): bool
    {
        if ($this->exists($visibility, $areaId, $apId, $filename, thumb: true)) {
            return true;
        }

        if (! $this->isImage($filename) || ! $this->exists($visibility, $areaId, $apId, $filename)) {
            return false;
        }

        $original = $this->disk()->path($this->path($visibility, $areaId, $apId, $filename));

        return $this->generateThumbnail(
            new File($original),
            $this->path($visibility, $areaId, $apId, $filename, thumb: true),
            Str::lower(pathinfo($filename, PATHINFO_EXTENSION)),
            ['visibility' => $visibility, 'area' => $areaId, 'ap' => $apId, 'filename' => basename($filename)],
        );
    }

    /**
     * Generate a thumbnail for an already-stored image, returning whether it was stored.
     *
     * A thumbnail failure must not lose the stored original, so errors are logged, not thrown.
     * Decoding a huge image can exhaust memory, which is a fatal (uncatchable) error, so the
     * memory need is estimated up front and the image is skipped if it cannot be afforded.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateThumbnail(SplFileInfo $file, string $thumbPath, string $extension, array $context): bool
    {
        $previousMemoryLimit = ini_get('memory_limit');

        try {
            if (! $this->reserveDecodingMemory($file->getRealPath())) {
                Log::warning('Gallery: image too large to generate a thumbnail', $context);

                return false;
            }

            $thumbnail = Image::decode($file->getRealPath())
                ->scaleDown(width: self::THUMBNAIL_WIDTH)
                ->encode(new FileExtensionEncoder($extension, quality: 80));

            return $this->disk()->put($thumbPath, (string) $thumbnail);
        } catch (\Throwable $e) {
            Log::error('Gallery: failed to generate thumbnail', [...$context, 'exception' => $e->getMessage()]);

            return false;
        } finally {
            ini_set('memory_limit', (string) $previousMemoryLimit);
        }
    }

    /**
     * Ensure enough memory is available to decode the image, raising the memory limit up to
     * {@see self::MAX_THUMBNAIL_MEMORY} when needed. Returns false if it cannot be afforded.
     */
    private function reserveDecodingMemory(string $path): bool
    {
        $size = @getimagesize($path);

        if ($size === false) {
            return false;
        }

        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($limit < 0) {
            return true;
        }

        $pixels = $size[0] * $size[1] * $this->frameCount($path, $size[2]);
        $required = memory_get_usage(true) + $pixels * self::DECODE_BYTES_PER_PIXEL;

        if ($required <= $limit) {
            return true;
        }

        if ($required > self::MAX_THUMBNAIL_MEMORY) {
            return false;
        }

        return ini_set('memory_limit', (string) $required) !== false;
    }

    /**
     * Number of frames the decoder will hold in memory: animated GIFs decode every frame at
     * full canvas size. Frames are counted by their Graphic Control Extension blocks, reading
     * in blocks so a huge file is never loaded at once. Other formats count as one frame.
     */
    private function frameCount(string $path, int $imageType): int
    {
        if ($imageType !== IMAGETYPE_GIF || ($handle = fopen($path, 'rb')) === false) {
            return 1;
        }

        $frames = 0;
        $carry = '';

        try {
            while (! feof($handle)) {
                $buffer = $carry.fread($handle, 1024 * 1024);
                $frames += preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $buffer);

                // Keep one byte less than a full match so a block split across reads is counted once.
                $carry = substr($buffer, -9);
            }
        } finally {
            fclose($handle);
        }

        return max(1, $frames);
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
     * Relative path of the file recording how many chunks of an upload have been received.
     */
    private function progressPath(string $uploadId): string
    {
        return Str::replaceLast('.part', '.progress', $this->tmpPath($uploadId));
    }

    /**
     * Number of chunks received so far for an upload, per its progress file.
     */
    private function receivedChunks(string $uploadId): int
    {
        $progress = $this->disk()->get($this->progressPath($uploadId));

        return $progress === null ? 0 : (int) $progress;
    }

    /**
     * Occasionally delete orphaned chunk and progress files left behind by interrupted uploads.
     * Runs on a lottery so it costs nothing on the vast majority of requests.
     */
    private function pruneStaleUploads(): void
    {
        Lottery::odds(1, 50)->winner(function (): void {
            $disk = $this->disk();
            $cutoff = now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();

            foreach ($disk->files(self::TMP_DIR) as $path) {
                if ($disk->lastModified($path) < $cutoff) {
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

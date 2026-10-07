<?php

namespace App\Services\Confluence;

use App\Services\GalleryStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One attachment of a Confluence page.
 */
final readonly class ConfluenceAttachment
{
    /**
     * @param  string  $downloadPath  path relative to the Confluence base URL
     */
    public function __construct(
        public int $id,
        public string $filename,
        public string $mediaType,
        public int $size,
        public int $version,
        public CarbonImmutable $createdAt,
        public string $downloadPath,
    ) {}

    /** File extensions for the image media types the gallery stores. */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * The name to store the photo under: the attachment's name, with an extension matching its
     * media type added when it has none the gallery recognises (Confluence attachments are
     * often named without one, e.g. "Pohled směr Jih").
     */
    public function galleryFilename(): string
    {
        $name = trim($this->filename);
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, GalleryStorage::ALLOWED_EXTENSIONS, true)) {
            return $name;
        }

        return $name.'.'.(self::EXTENSIONS[$this->mediaType] ?? 'jpg');
    }
}

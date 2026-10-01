<?php

namespace App\Services\Confluence;

use Carbon\CarbonImmutable;

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
}

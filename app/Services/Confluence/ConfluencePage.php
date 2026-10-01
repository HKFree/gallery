<?php

namespace App\Services\Confluence;

/**
 * A Confluence page as needed for importing its photos.
 */
final readonly class ConfluencePage
{
    /**
     * @param  list<string>  $ancestors  titles of the parent pages, outermost first
     * @param  string  $body  storage-format body (XHTML with `ac:`/`ri:` elements)
     */
    public function __construct(
        public int $id,
        public string $title,
        public int $version,
        public string $spaceKey,
        public string $spaceName,
        public array $ancestors,
        public string $body,
        public string $url,
    ) {}
}

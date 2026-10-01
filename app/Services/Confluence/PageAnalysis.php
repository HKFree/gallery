<?php

namespace App\Services\Confluence;

/**
 * The photos a Confluence page yields, and what was left out and why.
 */
final readonly class PageAnalysis
{
    /**
     * @param  list<ConfluenceAttachment>  $photos  in page order
     * @param  list<array{name: string, reason: string}>  $skipped
     */
    public function __construct(
        public array $photos,
        public array $skipped,
    ) {}

    /**
     * Total size of the photos, in bytes.
     */
    public function totalSize(): int
    {
        return array_sum(array_map(fn (ConfluenceAttachment $photo): int => $photo->size, $this->photos));
    }
}

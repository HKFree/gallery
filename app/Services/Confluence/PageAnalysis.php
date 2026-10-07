<?php

namespace App\Services\Confluence;

/**
 * The photos a Confluence page yields, and what was left out and why.
 */
final readonly class PageAnalysis
{
    /**
     * @param  list<ConfluenceAttachment>  $photos  in page order, then attachments not shown on the page
     * @param  list<array{name: string, reason: string}>  $skipped
     * @param  int  $attachedOnly  how many of the photos are attachments the page doesn't show
     */
    public function __construct(
        public array $photos,
        public array $skipped,
        public int $attachedOnly = 0,
    ) {}

    /**
     * Total size of the photos, in bytes.
     */
    public function totalSize(): int
    {
        return array_sum(array_map(fn (ConfluenceAttachment $photo): int => $photo->size, $this->photos));
    }
}

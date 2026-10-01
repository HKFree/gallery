<?php

namespace App\Services\Confluence;

use App\Services\GalleryStorage;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Works out which photos a Confluence page shows, from its storage-format body:
 *
 * - a `gallery` macro shows the page's image attachments, narrowed by its `include` and
 *   `exclude` parameters and ordered by its `sort` / `reverse` parameters;
 * - `ac:image` elements embed single attachments, in page order.
 *
 * Images of other pages and external images (`ri:url`) are never fetched; they are reported
 * as skipped, as are attachments the gallery can't store (format, size).
 */
class PageAnalyzer
{
    private const AC = 'http://atlassian.com/content';

    private const RI = 'http://atlassian.com/resource/identifier';

    /** @var list<string> */
    private const IMAGE_MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** XML's own entities; every other (HTML) entity is converted before parsing. */
    private const XML_ENTITIES = ['amp', 'lt', 'gt', 'quot', 'apos'];

    /**
     * @param  list<ConfluenceAttachment>  $attachments  all attachments of the page
     */
    public function analyze(ConfluencePage $page, array $attachments): PageAnalysis
    {
        $xpath = $this->parse($page->body);

        if ($xpath === null) {
            return new PageAnalysis([], [['name' => $page->title, 'reason' => 'obsah stránky nelze přečíst']]);
        }

        $byName = [];

        foreach ($attachments as $attachment) {
            $byName[$attachment->filename] = $attachment;
        }

        $wanted = [];
        $skipped = [];

        foreach ($xpath->query('//ac:structured-macro[@ac:name="gallery"]') as $macro) {
            array_push($wanted, ...$this->galleryFilenames($xpath, $macro, $attachments));
        }

        foreach ($xpath->query('//ac:image') as $image) {
            $attachment = $xpath->query('ri:attachment', $image)->item(0);

            if ($attachment instanceof DOMElement) {
                $filename = $attachment->getAttributeNS(self::RI, 'filename');

                if ($xpath->query('ri:page | ri:blog-post', $attachment)->length > 0) {
                    $skipped[] = ['name' => $filename, 'reason' => 'obrázek z jiné stránky'];
                } else {
                    $wanted[] = $filename;
                }

                continue;
            }

            $url = $xpath->query('ri:url', $image)->item(0);

            if ($url instanceof DOMElement) {
                $skipped[] = ['name' => $url->getAttributeNS(self::RI, 'value'), 'reason' => 'externí obrázek'];
            }
        }

        $photos = [];

        foreach (array_values(array_unique($wanted)) as $filename) {
            $attachment = $byName[$filename] ?? null;
            $reason = $attachment === null ? 'příloha nenalezena' : $this->rejectionReason($attachment);

            if ($reason === null) {
                $photos[] = $attachment;
            } else {
                $skipped[] = ['name' => $filename, 'reason' => $reason];
            }
        }

        return new PageAnalysis($photos, $skipped);
    }

    /**
     * File names a gallery macro shows, in its order.
     *
     * @param  list<ConfluenceAttachment>  $attachments
     * @return list<string>
     */
    private function galleryFilenames(DOMXPath $xpath, DOMElement $macro, array $attachments): array
    {
        $include = $this->filenameList($xpath, $macro, 'include');
        $exclude = $this->filenameList($xpath, $macro, 'exclude');
        $sort = $this->parameter($xpath, $macro, 'sort') ?? 'name';

        $images = collect($attachments)
            ->filter(fn (ConfluenceAttachment $attachment): bool => str_starts_with($attachment->mediaType, 'image/'))
            ->when($include !== [], fn ($images) => $images->filter(fn (ConfluenceAttachment $attachment): bool => in_array($attachment->filename, $include, true)))
            ->reject(fn (ConfluenceAttachment $attachment): bool => in_array($attachment->filename, $exclude, true));

        $images = $sort === 'date'
            ? $images->sortBy(fn (ConfluenceAttachment $attachment): int => $attachment->createdAt->getTimestamp())
            : $images->sortBy(fn (ConfluenceAttachment $attachment): string => $attachment->filename, SORT_NATURAL | SORT_FLAG_CASE);

        if ($this->parameter($xpath, $macro, 'reverse') === 'true') {
            $images = $images->reverse();
        }

        return $images->map(fn (ConfluenceAttachment $attachment): string => $attachment->filename)->values()->all();
    }

    /**
     * A macro parameter holding file names: comma-separated text, or `ri:attachment` elements.
     *
     * @return list<string>
     */
    private function filenameList(DOMXPath $xpath, DOMElement $macro, string $name): array
    {
        $parameter = $xpath->query("ac:parameter[@ac:name=\"{$name}\"]", $macro)->item(0);

        if (! $parameter instanceof DOMElement) {
            return [];
        }

        $filenames = [];

        foreach ($xpath->query('.//ri:attachment', $parameter) as $attachment) {
            $filenames[] = $attachment->getAttributeNS(self::RI, 'filename');
        }

        if ($filenames === []) {
            $filenames = explode(',', $parameter->textContent);
        }

        return array_values(array_filter(array_map('trim', $filenames), fn (string $filename): bool => $filename !== ''));
    }

    private function parameter(DOMXPath $xpath, DOMElement $macro, string $name): ?string
    {
        $parameter = $xpath->query("ac:parameter[@ac:name=\"{$name}\"]", $macro)->item(0);

        return $parameter === null ? null : trim($parameter->textContent);
    }

    /**
     * Why the gallery can't store this attachment, or null when it can.
     */
    private function rejectionReason(ConfluenceAttachment $attachment): ?string
    {
        // The media type decides; a missing or odd extension is fixed when storing.
        if (! in_array($attachment->mediaType, self::IMAGE_MEDIA_TYPES, true)) {
            return 'nepodporovaný formát';
        }

        if ($attachment->size > GalleryStorage::MAX_UPLOAD_KB * 1024) {
            return 'soubor je větší než 50 MB';
        }

        return null;
    }

    /**
     * Parse a storage-format body. It is not stand-alone XML: it uses the `ac`/`ri` namespace
     * prefixes without declaring them, and HTML entities such as `&nbsp;`. Network access and
     * entity substitution stay disabled (no XXE).
     */
    private function parse(string $body): ?DOMXPath
    {
        $body = preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]*);/', function (array $match): string {
            if (in_array($match[1], self::XML_ENTITIES, true)) {
                return $match[0];
            }

            $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $decoded === $match[0]
                ? '&amp;'.$match[1].';'
                : mb_encode_numericentity($decoded, [0x0, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        }, $body);

        $document = new DOMDocument;
        $xml = '<root xmlns:ac="'.self::AC.'" xmlns:ri="'.self::RI.'">'.$body.'</root>';

        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ac', self::AC);
        $xpath->registerNamespace('ri', self::RI);

        return $xpath;
    }
}

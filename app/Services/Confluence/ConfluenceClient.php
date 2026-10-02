<?php

namespace App\Services\Confluence;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads pages and attachments from the configured Confluence (Data Center REST API v1).
 *
 * Only the configured base URL is ever contacted: a pasted URL is parsed for a page id and
 * must point at the same host, and redirects are not followed, so a page can't make the
 * server fetch anything else (SSRF).
 */
class ConfluenceClient
{
    private const PAGE_SIZE = 200;

    /**
     * The page id a pasted Confluence URL refers to, or null when the URL is not a supported
     * page URL on the configured host.
     *
     * Supported: `/spaces/{key}/pages/{id}/…`, blog posts `/spaces/{key}/blog/{y}/{m}/{d}/{id}/…`,
     * `/pages/viewpage.action?pageId={id}` and
     * `/display/{key}/{title}` (looked up by title).
     */
    public function pageIdFromUrl(string $url): ?int
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || strcasecmp($parts['host'] ?? '', (string) parse_url($this->baseUrl(), PHP_URL_HOST)) !== 0) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (preg_match('#/spaces/[^/]+/(?:pages|blog/\d{4}/\d{2}/\d{2})/(\d+)(?:/|$)#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        if (str_ends_with($path, '/pages/viewpage.action')) {
            parse_str($parts['query'] ?? '', $query);

            return is_string($query['pageId'] ?? null) && ctype_digit($query['pageId']) ? (int) $query['pageId'] : null;
        }

        if (preg_match('#/display/([^/]+)/([^/]+)/?$#', $path, $matches) === 1) {
            return $this->pageIdByTitle(urldecode($matches[1]), urldecode($matches[2]));
        }

        return null;
    }

    /**
     * @throws ConfluenceException
     */
    public function page(int $pageId): ConfluencePage
    {
        $data = $this->get("/rest/api/content/{$pageId}", ['expand' => 'body.storage,version,space,ancestors'])->json();

        if (! in_array($data['type'] ?? null, ['page', 'blogpost'], true)) {
            throw new ConfluenceException('Adresa nevede na stránku Confluence.');
        }

        return new ConfluencePage(
            id: (int) $data['id'],
            title: (string) $data['title'],
            version: (int) ($data['version']['number'] ?? 1),
            spaceKey: (string) ($data['space']['key'] ?? ''),
            spaceName: (string) ($data['space']['name'] ?? ''),
            ancestors: array_values(array_map(fn (array $ancestor): string => (string) $ancestor['title'], $data['ancestors'] ?? [])),
            body: (string) ($data['body']['storage']['value'] ?? ''),
            url: $this->baseUrl().($data['_links']['webui'] ?? "/pages/viewpage.action?pageId={$pageId}"),
        );
    }

    /**
     * All attachments of a page (every API page of results).
     *
     * @return list<ConfluenceAttachment>
     *
     * @throws ConfluenceException
     */
    public function attachments(int $pageId): array
    {
        $attachments = [];
        $start = 0;

        do {
            $data = $this->get("/rest/api/content/{$pageId}/child/attachment", [
                'limit' => self::PAGE_SIZE, 'start' => $start, 'expand' => 'version',
            ])->json();

            foreach ($data['results'] ?? [] as $attachment) {
                $attachments[] = new ConfluenceAttachment(
                    id: (int) $attachment['id'],
                    filename: (string) $attachment['title'],
                    mediaType: (string) ($attachment['extensions']['mediaType'] ?? $attachment['metadata']['mediaType'] ?? ''),
                    size: (int) ($attachment['extensions']['fileSize'] ?? 0),
                    version: (int) ($attachment['version']['number'] ?? 1),
                    createdAt: CarbonImmutable::parse((string) ($attachment['version']['when'] ?? 'now')),
                    downloadPath: (string) ($attachment['_links']['download'] ?? ''),
                );
            }

            $start += self::PAGE_SIZE;
        } while (count($data['results'] ?? []) === self::PAGE_SIZE);

        return $attachments;
    }

    /**
     * Child pages of a page, to offer when a page has no photos of its own.
     *
     * @return list<array{id: int, title: string, url: string}>
     *
     * @throws ConfluenceException
     */
    public function childPages(int $pageId): array
    {
        $data = $this->get("/rest/api/content/{$pageId}/child/page", ['limit' => self::PAGE_SIZE])->json();

        return array_values(array_map(fn (array $child): array => [
            'id' => (int) $child['id'],
            'title' => (string) $child['title'],
            'url' => $this->baseUrl().($child['_links']['webui'] ?? "/pages/viewpage.action?pageId={$child['id']}"),
        ], $data['results'] ?? []));
    }

    /**
     * Stream an attachment into a local file, aborting once it exceeds $maxBytes.
     *
     * @param  string  $downloadPath  the attachment's download link, relative to the base URL
     *
     * @throws ConfluenceException
     */
    public function download(string $downloadPath, string $targetPath, int $maxBytes, int $timeoutSeconds = 50): void
    {
        if (! str_starts_with($downloadPath, '/download/')) {
            throw new ConfluenceException('Neplatný odkaz na přílohu.');
        }

        $tooLarge = false;
        $progress = function (int|float $expected, int|float $received) use ($maxBytes, &$tooLarge): void {
            if ($expected > $maxBytes || $received > $maxBytes) {
                $tooLarge = true;

                throw new RuntimeException('Download exceeds the size limit.');
            }
        };

        try {
            $response = $this->request(retry: false)
                ->timeout($timeoutSeconds)
                ->withOptions(['sink' => $targetPath, 'progress' => $progress])
                ->get($downloadPath);
        } catch (Throwable) {
            throw new ConfluenceException($tooLarge ? 'Soubor je větší než povolený limit.' : 'Stažení přílohy se nezdařilo.');
        }

        if (! $response->successful()) {
            throw new ConfluenceException("Stažení přílohy se nezdařilo (chyba {$response->status()}).");
        }

        if (filesize($targetPath) > $maxBytes) {
            throw new ConfluenceException('Soubor je větší než povolený limit.');
        }
    }

    /**
     * @throws ConfluenceException
     */
    private function pageIdByTitle(string $spaceKey, string $title): ?int
    {
        $data = $this->get('/rest/api/content', ['spaceKey' => $spaceKey, 'title' => str_replace('+', ' ', $title), 'type' => 'page'])->json();

        $id = $data['results'][0]['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, int|string>  $query
     *
     * @throws ConfluenceException
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException) {
            throw new ConfluenceException('Confluence není dostupný.');
        }

        if ($response->notFound() || $response->forbidden() || $response->unauthorized()) {
            throw new ConfluenceException('Stránka nebyla nalezena nebo k ní není přístup.');
        }

        if (! $response->successful()) {
            throw new ConfluenceException("Confluence odpověděl chybou {$response->status()}.");
        }

        return $response;
    }

    private function request(bool $retry = true): PendingRequest
    {
        $token = config('services.confluence.token');

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->when($retry, fn (PendingRequest $request) => $request->retry(2, 500, throw: false))
            ->withOptions(['allow_redirects' => false])
            ->when(filled($token), fn (PendingRequest $request) => $request->withToken((string) $token));
    }

    private function baseUrl(): string
    {
        return (string) config('services.confluence.base_url');
    }
}

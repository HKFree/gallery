<?php

namespace App\Http\Controllers;

use App\Services\Confluence\ConfluenceClient;
use App\Services\Confluence\ConfluenceException;
use App\Services\Confluence\ConfluencePage;
use App\Services\Confluence\PageAnalyzer;
use App\Services\UserdbService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ConfluenceImportController extends Controller
{
    public function __construct(
        private readonly UserdbService $userdb,
        private readonly ConfluenceClient $confluence,
        private readonly PageAnalyzer $analyzer,
    ) {}

    /**
     * The import form for one gallery; with `?url=`, also a preview of what that Confluence
     * page would import. Reads only, writes nothing.
     */
    public function create(Request $request, int $area, int $ap, string $visibility): View
    {
        $gallery = $this->userdb->findAp($area, $ap);

        abort_if($gallery === null, 404);

        $validated = $request->validate(['url' => ['nullable', 'string', 'max:2000']]);
        $url = trim((string) ($validated['url'] ?? ''));
        $data = ['visibility' => $visibility, 'area' => $gallery['area'], 'ap' => $gallery, 'url' => $url, 'preview' => null, 'error' => null];

        if ($url === '') {
            return view('confluence.import', $data);
        }

        try {
            $pageId = $this->confluence->pageIdFromUrl($url);

            if ($pageId === null) {
                $host = parse_url((string) config('services.confluence.base_url'), PHP_URL_HOST);

                return view('confluence.import', [...$data, 'error' => "Nepodporovaná adresa. Vložte odkaz na stránku z {$host}."]);
            }

            $page = $this->confluence->page($pageId);
            $analysis = $this->analyzer->analyze($page, $this->confluence->attachments($pageId));

            $data['preview'] = [
                'page' => $page,
                'analysis' => $analysis,
                'children' => $analysis->photos === [] ? $this->confluence->childPages($pageId) : [],
                'matchesAp' => $this->matchesAp($page, $gallery['name']),
            ];
        } catch (ConfluenceException $exception) {
            $data['error'] = $exception->getMessage();
        }

        return view('confluence.import', $data);
    }

    /**
     * Whether the page or one of its parents looks like it belongs to this AP. Confluence page
     * names only roughly follow AP names ("Plotiště AP", "Benesovka"), so this only drives a
     * warning.
     */
    private function matchesAp(ConfluencePage $page, string $apName): bool
    {
        $normalize = fn (string $name): string => trim((string) preg_replace(
            ['/\b(sub)?ap\b/', '/[^a-z0-9]+/'], ['', ' '], Str::lower(Str::ascii($name)),
        ));

        $ap = $normalize($apName);

        if ($ap === '') {
            return false;
        }

        foreach ([$page->title, ...$page->ancestors] as $title) {
            $candidate = $normalize($title);

            if ($candidate !== '' && (str_contains($candidate, $ap) || str_contains($ap, $candidate))) {
                return true;
            }
        }

        return false;
    }
}

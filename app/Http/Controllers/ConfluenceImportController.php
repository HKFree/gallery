<?php

namespace App\Http\Controllers;

use App\Models\ConfluenceImport;
use App\Services\Confluence\ConfluenceClient;
use App\Services\Confluence\ConfluenceException;
use App\Services\Confluence\ConfluenceImporter;
use App\Services\Confluence\ConfluencePage;
use App\Services\Confluence\PageAnalyzer;
use App\Services\UserdbService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ConfluenceImportController extends Controller
{
    public function __construct(
        private readonly UserdbService $userdb,
        private readonly ConfluenceClient $confluence,
        private readonly PageAnalyzer $analyzer,
        private readonly ConfluenceImporter $importer,
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

            // Count by attachment version, as the import does: a new version is a new photo.
            $imported = $this->importer->alreadyImported($visibility, $area, $ap, $analysis->photos);
            $alreadyImported = count(array_filter($analysis->photos, fn ($photo): bool => isset($imported["{$photo->id}:{$photo->version}"])));

            $data['preview'] = [
                'page' => $page,
                'analysis' => $analysis,
                'alreadyImported' => $alreadyImported,
                'newPhotos' => count($analysis->photos) - $alreadyImported,
                'children' => $analysis->photos === [] ? $this->confluence->childPages($pageId) : [],
                'matchesAp' => $this->matchesAp($page, $gallery['name']),
            ];
        } catch (ConfluenceException $exception) {
            $data['error'] = $exception->getMessage();
        }

        return view('confluence.import', $data);
    }

    /**
     * Start importing the page's new photos into this gallery, then show the import's progress.
     */
    public function store(Request $request, int $area, int $ap, string $visibility): RedirectResponse
    {
        abort_if($this->userdb->findAp($area, $ap) === null, 404);

        $url = (string) $request->validate(['url' => ['required', 'string', 'max:2000']])['url'];
        $back = route('confluence.import.create', ['visibility' => $visibility, 'area' => $area, 'ap' => $ap, 'url' => $url]);

        try {
            $pageId = $this->confluence->pageIdFromUrl($url) ?? throw new ConfluenceException('Nepodporovaná adresa.');
            $import = $this->importer->start($request->user(), $visibility, $area, $ap, $pageId);
        } catch (ConfluenceException $exception) {
            return redirect($back)->with('error', $exception->getMessage());
        }

        return redirect()->route('confluence.import.show', ['visibility' => $visibility, 'area' => $area, 'ap' => $ap, 'import' => $import]);
    }

    /**
     * Progress and result of an import into this gallery.
     */
    public function show(int $area, int $ap, string $visibility, ConfluenceImport $import): View
    {
        $gallery = $this->userdb->findAp($area, $ap);

        abort_if($gallery === null || $import->visibility !== $visibility || $import->area_id !== $area || $import->ap_id !== $ap, 404);

        return view('confluence.status', [
            'visibility' => $visibility,
            'area' => $gallery['area'],
            'ap' => $gallery,
            'import' => $import,
            'counts' => $import->itemCounts(),
            'failures' => $import->items()->where('status', 'failed')->orderBy('id')->get(['original_filename', 'reason']),
        ]);
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

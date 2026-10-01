<?php

namespace App\Http\Controllers;

use App\Models\GalleryImage;
use App\Services\GalleryIndex;
use App\Services\Timeline;
use App\Services\UserdbService;
use App\Support\GalleryLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TimelineController extends Controller
{
    /** At most this many unindexed files are indexed while rendering a timeline page. */
    private const RECONCILE_LIMIT = 200;

    public function __construct(
        private readonly UserdbService $userdb,
        private readonly GalleryIndex $index,
        private readonly Timeline $timeline,
    ) {}

    public function showPublic(Request $request, int $area, int $ap): View
    {
        return $this->showGallery($request, 'pub', $area, $ap);
    }

    public function showPrivate(Request $request, int $area, int $ap): View
    {
        return $this->showGallery($request, 'priv', $area, $ap);
    }

    /**
     * One AP gallery's images grouped by month, newest first.
     *
     * The first page reconciles the gallery's index with its directory (capped), so files
     * copied onto the server appear without waiting for the scheduled `gallery:index`.
     * Requests for further pages (`X-Requested-With`) get just the next page's fragment.
     */
    private function showGallery(Request $request, string $visibility, int $areaId, int $apId): View
    {
        $ap = $this->userdb->findAp($areaId, $apId);

        abort_if($ap === null, 404);

        $pending = $request->has('cursor')
            ? 0
            : $this->index->reconcileAp($visibility, $areaId, $apId, self::RECONCILE_LIMIT)['pending'];

        $query = GalleryImage::query()->where(['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId]);
        $from = $request->string('from')->toString() ?: null;
        $months = $this->timeline->months($query);
        $page = $this->timeline->page($query, $from);
        $canManage = Gate::allows('manage-gallery');

        $sections = $this->timeline->sections($page, $months, fn (GalleryImage $image): array => GalleryLinks::image(
            $visibility, $areaId, $apId, $image->filename,
        ));

        if ($request->ajax()) {
            return view('timeline.fragment', ['sections' => $sections, 'nextUrl' => $page->nextPageUrl(), 'canManage' => $canManage]);
        }

        $route = $visibility === 'priv' ? 'gallery.private.timeline' : 'gallery.public.timeline';

        return view('gallery.timeline', [
            'visibility' => $visibility,
            'area' => $ap['area'],
            'ap' => $ap,
            'months' => $months,
            'sections' => $sections,
            'nextUrl' => $page->nextPageUrl(),
            'newestUrl' => $this->isAtNewest($page->previousCursor() !== null, $from, $months->first()['month'] ?? null)
                ? null
                : route($route, ['area' => $areaId, 'ap' => $apId]),
            'timelineUrl' => route($route, ['area' => $areaId, 'ap' => $apId]),
            'pending' => $pending,
            'canManage' => $canManage,
        ]);
    }

    /**
     * Whether the page starts at the newest image (so no "Novější" link is needed).
     */
    private function isAtNewest(bool $hasPreviousPage, ?string $from, ?string $newestMonth): bool
    {
        return ! $hasPreviousPage && ($from === null || ! $this->timeline->isMonth($from) || $newestMonth === null || $from >= $newestMonth);
    }
}

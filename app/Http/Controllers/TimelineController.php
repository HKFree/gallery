<?php

namespace App\Http\Controllers;

use App\Models\GalleryImage;
use App\Services\GalleryDescriptions;
use App\Services\GalleryIndex;
use App\Services\Timeline;
use App\Services\UserdbService;
use App\Support\GalleryLinks;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly GalleryDescriptions $descriptions,
    ) {}

    /**
     * The network timeline: images of every AP gallery, newest first.
     *
     * Guests see public galleries only; signed-in users may add the private ("Dokumentace")
     * galleries with `?priv=1`. Images of APs no longer listed in Userdb are left out, since
     * their gallery routes would 404.
     */
    public function index(Request $request): View
    {
        $includePrivate = $request->user() !== null && $request->boolean('priv');

        $aps = $this->userdb->aps();

        $images = GalleryImage::query()
            ->whereIn('visibility', $includePrivate ? ['pub', 'priv'] : ['pub'])
            ->whereIn('ap_id', $aps->keys()->all());

        return $this->respond(
            $request,
            $images,
            fn (GalleryImage $image): array => [
                ...GalleryLinks::image($image->visibility, $image->area_id, $image->ap_id, $image->filename),
                'caption' => $aps[$image->ap_id]['name'],
                'caption_url' => route($image->visibility === 'priv' ? 'gallery.private.timeline' : 'gallery.public.timeline', [
                    'area' => $image->area_id, 'ap' => $image->ap_id,
                ]),
                'locked' => $image->visibility === 'priv',
            ],
            canManage: false,
            view: 'timeline.index',
            url: route('timeline'),
            query: $includePrivate ? ['priv' => 1] : [],
            data: ['includePrivate' => $includePrivate],
        );
    }

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
     */
    private function showGallery(Request $request, string $visibility, int $areaId, int $apId): View
    {
        $ap = $this->userdb->findAp($areaId, $apId);

        abort_if($ap === null, 404);

        $pending = $request->has('cursor')
            ? 0
            : $this->index->reconcileAp($visibility, $areaId, $apId, self::RECONCILE_LIMIT)['pending'];

        return $this->respond(
            $request,
            GalleryImage::query()->where(['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId]),
            fn (GalleryImage $image): array => GalleryLinks::image($visibility, $areaId, $apId, $image->filename),
            canManage: Gate::allows('manage-gallery'),
            view: 'gallery.timeline',
            url: route($visibility === 'priv' ? 'gallery.private.timeline' : 'gallery.public.timeline', ['area' => $areaId, 'ap' => $apId]),
            data: ['visibility' => $visibility, 'area' => $ap['area'], 'ap' => $ap, 'pending' => $pending],
        );
    }

    /**
     * Render a timeline page for the given images. Script requests for further pages
     * (`X-Requested-With`) get just the next page's fragment.
     *
     * @param  Builder<GalleryImage>  $images
     * @param  callable(GalleryImage): array<string, mixed>  $tile
     * @param  array<string, int|string>  $query  extra query parameters every timeline link keeps
     * @param  array<string, mixed>  $data  extra view data
     */
    private function respond(Request $request, Builder $images, callable $tile, bool $canManage, string $view, string $url, array $query = [], array $data = []): View
    {
        $from = $request->string('from')->toString() ?: null;
        $months = $this->timeline->months($images);
        $page = $this->timeline->page($images, $from);
        $descriptions = $this->descriptions->textsForImages($page->items());

        $sections = $this->timeline->sections($page, $months, fn (GalleryImage $image): array => [
            ...$tile($image),
            'description' => $descriptions["{$image->visibility}/{$image->area_id}/{$image->ap_id}/{$image->filename}"] ?? null,
        ]);

        if ($request->ajax()) {
            return view('timeline.fragment', ['sections' => $sections, 'nextUrl' => $page->nextPageUrl(), 'canManage' => $canManage]);
        }

        $atNewest = $page->previousCursor() === null
            && (! $this->timeline->isMonth($from) || $months->isEmpty() || $from >= $months->first()['month']);

        return view($view, [
            ...$data,
            'months' => $months,
            'sections' => $sections,
            'nextUrl' => $page->nextPageUrl(),
            'newestUrl' => $atNewest ? null : $url.($query === [] ? '' : '?'.http_build_query($query)),
            'timelineUrl' => $url,
            'timelineQuery' => $query,
            'canManage' => $canManage,
        ]);
    }
}

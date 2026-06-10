<?php

namespace App\Http\Controllers;

use App\Services\GalleryStorage;
use App\Services\UserdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class GalleryController extends Controller
{
    public function __construct(
        private readonly UserdbService $userdb,
        private readonly GalleryStorage $storage,
    ) {}

    public function showPublic(int $area, int $ap): View
    {
        return $this->showGallery('pub', $area, $ap);
    }

    public function showPrivate(int $area, int $ap): View
    {
        return $this->showGallery('priv', $area, $ap);
    }

    /**
     * Store one or more uploaded images (SO role only).
     */
    public function upload(Request $request, string $visibility, int $area, int $ap): RedirectResponse|JsonResponse
    {
        $this->resolveAp($area, $ap);

        $validated = $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['required', 'file', 'image', 'max:10240'],
        ]);

        foreach ($validated['files'] as $file) {
            $this->storage->store($visibility, $area, $ap, $file);
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'ok', 'count' => count($validated['files'])]);
        }

        return back();
    }

    /**
     * Soft-delete an image (SO role only).
     */
    public function destroy(Request $request, string $visibility, int $area, int $ap, string $filename): RedirectResponse|JsonResponse
    {
        $this->resolveAp($area, $ap);

        $this->storage->trash($visibility, $area, $ap, $filename);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return back();
    }

    private function showGallery(string $visibility, int $areaId, int $apId): View
    {
        $ap = $this->resolveAp($areaId, $apId);

        return view('gallery.show', [
            'visibility' => $visibility,
            'area' => $ap['area'],
            'ap' => $ap,
            'images' => $this->images($visibility, $areaId, $apId),
            'canManage' => Gate::allows('manage-gallery'),
        ]);
    }

    /**
     * Resolve and validate the AP against Userdb, 404 when unknown.
     *
     * @return array{id: int, name: string, active: bool, area: array{id: int, name: string}}
     */
    private function resolveAp(int $areaId, int $apId): array
    {
        $ap = $this->userdb->findAp($areaId, $apId);

        abort_if($ap === null, 404);

        return $ap;
    }

    /**
     * Build the image view-model (URLs differ by visibility).
     *
     * @return list<array{name: string, url: string, thumb_url: string, delete_url: string}>
     */
    private function images(string $visibility, int $areaId, int $apId): array
    {
        $names = $this->storage->imageNames($visibility, $areaId, $apId);

        return array_map(function (string $name) use ($visibility, $areaId, $apId): array {
            $deleteUrl = route('gallery.destroy', [
                'visibility' => $visibility, 'area' => $areaId, 'ap' => $apId, 'filename' => $name,
            ]);

            if ($visibility === 'priv') {
                return [
                    'name' => $name,
                    'url' => route('gallery.private.image', ['area' => $areaId, 'ap' => $apId, 'filename' => $name]),
                    'thumb_url' => route('gallery.private.thumb', ['area' => $areaId, 'ap' => $apId, 'filename' => $name]),
                    'delete_url' => $deleteUrl,
                ];
            }

            $disk = Storage::disk('public');

            return [
                'name' => $name,
                'url' => $disk->url($this->storage->path($visibility, $areaId, $apId, $name)),
                'thumb_url' => $disk->url($this->storage->path($visibility, $areaId, $apId, $name, thumb: true)),
                'delete_url' => $deleteUrl,
            ];
        }, $names);
    }
}

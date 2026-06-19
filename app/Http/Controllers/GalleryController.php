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
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function publicImage(int $area, int $ap, string $filename): StreamedResponse
    {
        return $this->streamImage('pub', $area, $ap, $filename, thumb: false);
    }

    public function publicThumb(int $area, int $ap, string $filename): StreamedResponse
    {
        return $this->streamImage('pub', $area, $ap, $filename, thumb: true);
    }

    public function privateImage(int $area, int $ap, string $filename): StreamedResponse
    {
        return $this->streamImage('priv', $area, $ap, $filename, thumb: false);
    }

    public function privateThumb(int $area, int $ap, string $filename): StreamedResponse
    {
        return $this->streamImage('priv', $area, $ap, $filename, thumb: true);
    }

    /**
     * Receive one chunk of a chunked image upload (SO role only).
     *
     * The client slices each image into ~1 MB chunks (to stay under PHP's upload limits)
     * and POSTs them in order under a shared `upload_id`. Chunks are appended to a temp
     * file; the final chunk triggers validation, storage and thumbnail generation.
     */
    public function uploadChunk(Request $request, int $area, int $ap, string $visibility): JsonResponse
    {
        $this->resolveAp($area, $ap);

        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'uuid'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:60'],
            'filename' => ['required', 'string', 'max:255'],
            'chunk' => ['required', 'file', 'max:2048'],
        ], [
            'chunk.uploaded' => 'Část souboru se nepodařilo nahrát.',
            'chunk.max' => 'Část souboru je příliš velká.',
        ]);

        try {
            $this->storage->appendChunk($validated['upload_id'], $request->file('chunk'), (int) $validated['chunk_index']);

            if ((int) $validated['chunk_index'] < (int) $validated['total_chunks'] - 1) {
                return response()->json(['status' => 'pending']);
            }

            $filename = $this->storage->assembleUpload($visibility, $area, $ap, $validated['upload_id'], $validated['filename']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'ok', 'filename' => $filename]);
    }

    /**
     * Soft-delete an image (SO role only).
     */
    public function destroy(Request $request, int $area, int $ap, string $visibility, string $filename): RedirectResponse|JsonResponse
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
     * Build the image view-model. All images stream through the controller.
     *
     * @return list<array{name: string, url: string, thumb_url: string, delete_url: string}>
     */
    private function images(string $visibility, int $areaId, int $apId): array
    {
        $names = $this->storage->imageNames($visibility, $areaId, $apId);
        $route = $visibility === 'priv' ? 'private' : 'public';

        return array_map(fn (string $name): array => [
            'name' => $name,
            'url' => route("gallery.{$route}.image", ['area' => $areaId, 'ap' => $apId, 'filename' => $name]),
            'thumb_url' => route("gallery.{$route}.thumb", ['area' => $areaId, 'ap' => $apId, 'filename' => $name]),
            'delete_url' => route('gallery.destroy', [
                'visibility' => $visibility, 'area' => $areaId, 'ap' => $apId, 'filename' => $name,
            ]),
        ], $names);
    }

    /**
     * Stream an image (or its thumbnail) from the private disk, 404 when missing or trashed.
     */
    private function streamImage(string $visibility, int $areaId, int $apId, string $filename, bool $thumb): StreamedResponse
    {
        $this->resolveAp($areaId, $apId);

        $filename = basename($filename);

        abort_if($this->storage->isTrashed($filename), 404);

        $disk = Storage::disk('local');
        $path = $this->storage->path($visibility, $areaId, $apId, $filename, $thumb);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}

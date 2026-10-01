<?php

namespace App\Http\Controllers;

use App\Enums\Scene;
use App\Models\ConfluenceImport;
use App\Models\GalleryImageDescription;
use App\Services\GalleryDescriptions;
use App\Services\GalleryStorage;
use App\Services\UserdbService;
use App\Support\GalleryLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Managers' edits to photo descriptions: the view direction set by hand, and scene types
 * recognised in their browser.
 */
class DescriptionController extends Controller
{
    public function __construct(
        private readonly UserdbService $userdb,
        private readonly GalleryStorage $storage,
        private readonly GalleryDescriptions $descriptions,
    ) {}

    /**
     * Set or clear a photo's direction (`heading`), or store its scene type (`scene`, `score`).
     */
    public function update(Request $request, int $area, int $ap, string $visibility): JsonResponse|RedirectResponse
    {
        abort_if($this->userdb->findAp($area, $ap) === null, 404);

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'heading' => ['nullable', 'integer', 'between:0,359'],
            'scene' => ['nullable', Rule::enum(Scene::class)],
            'score' => ['required_with:scene', 'nullable', 'numeric', 'between:0,1'],
        ]);

        // An empty `heading` clears the direction, so presence (not a value) is what counts.
        if (! $request->has('heading') && empty($validated['scene'])) {
            throw ValidationException::withMessages(['heading' => 'Chybí směr nebo typ scény.']);
        }

        $filename = $validated['filename'];

        abort_if(
            basename($filename) !== $filename || $this->storage->isTrashed($filename) || ! $this->storage->exists($visibility, $area, $ap, $filename),
            404,
        );

        if (isset($validated['scene'])) {
            $this->descriptions->setScene($visibility, $area, $ap, $filename, Scene::from($validated['scene']), (float) $validated['score']);
        } else {
            $heading = $request->filled('heading') ? (int) $validated['heading'] : null;
            $this->descriptions->setHeading($visibility, $area, $ap, $filename, $heading, $request->user());
        }

        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json([
            'description' => $this->descriptions->texts($visibility, $area, $ap, [$filename])[$filename] ?? null,
        ]);
    }

    /**
     * Photos of this gallery without a scene type yet (optionally only those of one import),
     * for scene recognition in the browser.
     */
    public function sceneQueue(Request $request, int $area, int $ap, string $visibility): JsonResponse
    {
        abort_if($this->userdb->findAp($area, $ap) === null, 404);

        $names = $this->storage->imageNames($visibility, $area, $ap);

        if ($request->filled('import')) {
            $import = ConfluenceImport::query()->forGallery($visibility, $area, $ap)->findOrFail($request->integer('import'));
            $names = array_values(array_intersect($names, $import->items()->whereNotNull('stored_filename')->pluck('stored_filename')->all()));
        }

        $tagged = GalleryImageDescription::query()
            ->where(['visibility' => $visibility, 'area_id' => $area, 'ap_id' => $ap])
            ->whereNotNull('scene')
            ->pluck('filename')
            ->all();

        return response()->json(array_map(fn (string $name): array => [
            'filename' => $name,
            'thumb_url' => GalleryLinks::image($visibility, $area, $ap, $name)['thumb_url'],
        ], array_values(array_diff($names, $tagged))));
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Scene;
use App\Models\ConfluenceImport;
use App\Models\GalleryImageDescription;
use App\Models\GalleryImageEmbedding;
use App\Services\DirectionSuggestions;
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
 * Managers' edits to photo descriptions: the view direction set by hand or confirmed from a
 * suggestion, and what their browser recognised in the photos (scene type, image embedding).
 */
class DescriptionController extends Controller
{
    public function __construct(
        private readonly UserdbService $userdb,
        private readonly GalleryStorage $storage,
        private readonly GalleryDescriptions $descriptions,
        private readonly DirectionSuggestions $suggestions,
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
            'source' => ['nullable', Rule::in(['manual', 'similarity'])],
            'scene' => ['nullable', Rule::enum(Scene::class)],
            'score' => ['required_with:scene', 'nullable', 'numeric', 'between:0,1'],
        ]);

        // An empty `heading` clears the direction, so presence (not a value) is what counts.
        if (! $request->has('heading') && empty($validated['scene'])) {
            throw ValidationException::withMessages(['heading' => 'Chybí směr nebo typ scény.']);
        }

        $filename = $this->existingPhoto($visibility, $area, $ap, $validated['filename']);

        if (isset($validated['scene'])) {
            $this->descriptions->setScene($visibility, $area, $ap, $filename, Scene::from($validated['scene']), (float) $validated['score']);
        } else {
            $heading = $request->filled('heading') ? (int) $validated['heading'] : null;
            $this->descriptions->setHeading($visibility, $area, $ap, $filename, $heading, $request->user(), $validated['source'] ?? 'manual');
        }

        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json([
            'description' => $this->descriptions->texts($visibility, $area, $ap, [$filename])[$filename] ?? null,
        ]);
    }

    /**
     * Store a photo's image embedding computed in the browser (base64 of little-endian float32).
     */
    public function storeEmbedding(Request $request, int $area, int $ap, string $visibility): JsonResponse
    {
        abort_if($this->userdb->findAp($area, $ap) === null, 404);

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'model' => ['required', Rule::in([DirectionSuggestions::MODEL])],
            'vector' => ['required', 'string', 'max:8192'],
        ]);

        $filename = $this->existingPhoto($visibility, $area, $ap, $validated['filename']);
        $packed = base64_decode($validated['vector'], true);
        $vector = $packed !== false && strlen($packed) === DirectionSuggestions::DIMENSIONS * 4 ? array_values(unpack('g*', $packed)) : [];

        if ($vector === [] || array_filter($vector, fn (float $value): bool => ! is_finite($value)) !== []) {
            throw ValidationException::withMessages(['vector' => 'Neplatný vektor.']);
        }

        GalleryImageEmbedding::updateOrCreate(
            ['visibility' => $visibility, 'area_id' => $area, 'ap_id' => $ap, 'filename' => $filename],
            ['model' => $validated['model'], 'vector' => $vector],
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Confirm every current direction suggestion of this gallery at once.
     */
    public function confirmSuggestions(Request $request, int $area, int $ap, string $visibility): RedirectResponse
    {
        abort_if($this->userdb->findAp($area, $ap) === null, 404);

        $suggestions = $this->suggestions->forGallery($visibility, $area, $ap, $this->storage->imageNames($visibility, $area, $ap));

        foreach ($suggestions as $filename => $suggestion) {
            $this->descriptions->setHeading($visibility, $area, $ap, $filename, $suggestion['heading'], $request->user(), 'similarity');
        }

        return back()->with('status', 'Potvrzené návrhy směru: '.count($suggestions).'.');
    }

    /**
     * Photos of this gallery that still need analysing in the browser (optionally only those of
     * one import): a scene type, an image embedding for direction suggestions, or both.
     */
    public function analysisQueue(Request $request, int $area, int $ap, string $visibility): JsonResponse
    {
        abort_if($this->userdb->findAp($area, $ap) === null, 404);

        $names = $this->storage->imageNames($visibility, $area, $ap);

        if ($request->filled('import')) {
            $import = ConfluenceImport::query()->forGallery($visibility, $area, $ap)->findOrFail($request->integer('import'));
            $names = array_values(array_intersect($names, $import->items()->whereNotNull('stored_filename')->pluck('stored_filename')->all()));
        }

        $gallery = ['visibility' => $visibility, 'area_id' => $area, 'ap_id' => $ap];
        $tagged = GalleryImageDescription::query()->where($gallery)->whereNotNull('scene')->pluck('filename')->flip();
        $embedded = GalleryImageEmbedding::query()->where([...$gallery, 'model' => DirectionSuggestions::MODEL])->pluck('filename')->flip();

        return response()->json(collect($names)
            ->map(fn (string $name): array => [
                'filename' => $name,
                'thumb_url' => GalleryLinks::image($visibility, $area, $ap, $name)['thumb_url'],
                'scene' => ! $tagged->has($name),
                'embedding' => ! $embedded->has($name),
            ])
            ->filter(fn (array $photo): bool => $photo['scene'] || $photo['embedding'])
            ->values());
    }

    /**
     * A photo of this gallery, by its exact file name; 404 for paths, trashed and missing files.
     */
    private function existingPhoto(string $visibility, int $area, int $ap, string $filename): string
    {
        abort_if(
            basename($filename) !== $filename || $this->storage->isTrashed($filename) || ! $this->storage->exists($visibility, $area, $ap, $filename),
            404,
        );

        return $filename;
    }
}

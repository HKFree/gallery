<?php

namespace App\Services;

use App\Models\GalleryImageDescription;
use App\Models\GalleryImageEmbedding;
use App\Support\Compass;

/**
 * Suggests a photo's view direction from the most similar photo of the same AP whose direction
 * is known: the same view photographed in another year or season looks alike, so it shares the
 * direction. Similarity is the cosine of the photos' image embeddings (DINOv2, computed in a
 * manager's browser). Photos of both the public and the private gallery of the AP are compared.
 *
 * A suggestion is made only when the best match is similar enough and clearly ahead of the best
 * match pointing another way; a manager confirms it before it becomes part of the description.
 */
class DirectionSuggestions
{
    /** The embedding model the browser uses; vectors of other models are not compared. */
    public const MODEL = 'onnx-community/dinov2-small';

    /** Length of the model's embedding vector. */
    public const DIMENSIONS = 384;

    /** Minimum cosine similarity of the best match. */
    private const MIN_SIMILARITY = 0.7;

    /** How far the best match must lead the best match with a different direction. */
    private const MIN_LEAD = 0.05;

    /** Headings closer than this count as the same direction. */
    private const SAME_DIRECTION_DEGREES = 25;

    /**
     * Suggestions for the given photos of a gallery that have no direction yet.
     *
     * @param  list<string>  $filenames
     * @return array<string, array{heading: int, from: string, similarity: float}>
     */
    public function forGallery(string $visibility, int $areaId, int $apId, array $filenames): array
    {
        $vectors = GalleryImageEmbedding::query()
            ->where(['area_id' => $areaId, 'ap_id' => $apId, 'model' => self::MODEL])
            ->get()
            ->mapWithKeys(fn (GalleryImageEmbedding $embedding): array => [
                "{$embedding->visibility}/{$embedding->filename}" => $this->normalize($embedding->vector),
            ]);

        $headings = GalleryImageDescription::query()
            ->where(['area_id' => $areaId, 'ap_id' => $apId])
            ->whereNotNull('heading')
            ->get()
            ->mapWithKeys(fn (GalleryImageDescription $description): array => ["{$description->visibility}/{$description->filename}" => $description->heading]);

        $known = $vectors->intersectByKeys($headings);

        if ($known->isEmpty()) {
            return [];
        }

        $suggestions = [];

        foreach ($filenames as $filename) {
            $key = "{$visibility}/{$filename}";

            if ($headings->has($key) || ! $vectors->has($key)) {
                continue;
            }

            $suggestion = $this->suggest($vectors[$key], $known->except($key)->all(), $headings->all());

            if ($suggestion !== null) {
                $suggestions[$filename] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * @param  list<float>  $vector
     * @param  array<string, list<float>>  $known  normalized vectors of photos with a direction
     * @param  array<string, int>  $headings
     * @return array{heading: int, from: string, similarity: float}|null
     */
    private function suggest(array $vector, array $known, array $headings): ?array
    {
        $similarities = array_map(fn (array $other): float => $this->dot($vector, $other), $known);
        arsort($similarities);

        $bestKey = array_key_first($similarities);

        if ($bestKey === null || $similarities[$bestKey] < self::MIN_SIMILARITY) {
            return null;
        }

        $heading = $headings[$bestKey];

        foreach ($similarities as $key => $similarity) {
            if (Compass::angleBetween($headings[$key], $heading) > self::SAME_DIRECTION_DEGREES) {
                if ($similarities[$bestKey] - $similarity < self::MIN_LEAD) {
                    return null;
                }

                break;
            }
        }

        return [
            'heading' => $heading,
            'from' => explode('/', $bestKey, 2)[1],
            'similarity' => round($similarities[$bestKey], 3),
        ];
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        return $length > 0 ? array_map(fn (float $value): float => $value / $length, $vector) : $vector;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * ($b[$index] ?? 0.0);
        }

        return $sum;
    }
}

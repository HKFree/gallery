<?php

namespace Database\Factories;

use App\Models\GalleryImageEmbedding;
use App\Services\DirectionSuggestions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryImageEmbedding>
 */
class GalleryImageEmbeddingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'area_id' => 13,
            'ap_id' => 201,
            'visibility' => 'pub',
            'filename' => fake()->unique()->lexify('img-??????').'.jpg',
            'model' => DirectionSuggestions::MODEL,
            'vector' => array_map(fn () => fake()->randomFloat(4, -1, 1), range(1, 8)),
        ];
    }
}

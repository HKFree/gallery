<?php

namespace Database\Factories;

use App\Models\GalleryImageDescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryImageDescription>
 */
class GalleryImageDescriptionFactory extends Factory
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
        ];
    }
}

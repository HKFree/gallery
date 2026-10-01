<?php

namespace Database\Factories;

use App\Models\GalleryImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryImage>
 */
class GalleryImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $takenAt = fake()->dateTimeBetween('-5 years', '-1 day');

        return [
            'area_id' => 13,
            'ap_id' => 201,
            'visibility' => 'pub',
            'filename' => fake()->unique()->lexify('img-??????').'.jpg',
            'taken_at' => $takenAt,
            'client_modified_at' => null,
            'uploaded_at' => fake()->dateTimeBetween($takenAt, 'now'),
        ];
    }

    /**
     * An image without an EXIF taken date (sorted by its upload date).
     */
    public function withoutTakenDate(): static
    {
        return $this->state(fn (array $attributes) => [
            'taken_at' => null,
        ]);
    }

    /**
     * An image in the private ("Dokumentace") gallery.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => 'priv',
        ]);
    }
}

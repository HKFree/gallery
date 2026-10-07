<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\ConfluenceImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfluenceImport>
 */
class ConfluenceImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pageId = fake()->unique()->numberBetween(10_000_000, 99_999_999);

        return [
            'user_id' => null,
            'area_id' => 13,
            'ap_id' => 201,
            'visibility' => 'pub',
            'page_id' => $pageId,
            'page_title' => fake()->words(2, true),
            'page_version' => 1,
            'page_url' => "https://doc.hkfree.org/pages/viewpage.action?pageId={$pageId}",
            'status' => ImportStatus::Queued,
        ];
    }

    /**
     * A finished import.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Done,
        ]);
    }
}

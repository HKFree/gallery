<?php

namespace Database\Factories;

use App\Enums\ImportItemStatus;
use App\Models\ConfluenceImport;
use App\Models\ConfluenceImportItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfluenceImportItem>
 */
class ConfluenceImportItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->unique()->lexify('DSC_????').'.jpg';

        return [
            'confluence_import_id' => ConfluenceImport::factory(),
            'attachment_id' => fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'attachment_version' => 1,
            'original_filename' => $filename,
            'size' => 1000,
            'download_path' => "/download/attachments/1/{$filename}?version=1&api=v2",
            'attachment_created_at' => '2011-05-25 19:49:54',
            'status' => ImportItemStatus::Pending,
        ];
    }

    /**
     * An item whose photo was imported.
     */
    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportItemStatus::Imported,
            'stored_filename' => $attributes['original_filename'],
        ]);
    }
}

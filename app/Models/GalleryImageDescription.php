<?php

namespace App\Models;

use App\Enums\Scene;
use Database\Factories\GalleryImageDescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Facts about one gallery photo that its description is composed from: where it was taken
 * from (EXIF GPS; otherwise the AP's own coordinates are used), which way it looks, and the
 * recognised scene type.
 *
 * Kept apart from the derived `gallery_images` index, so a manually set direction survives
 * re-indexing. `heading_source` is `exif`, `filename`, `manual` or `similarity` (a confirmed
 * suggestion). `obstruction` is the share of the view blocked by near trees (`obstruction_kind`
 * `trees`) or other obstacles (`other`); `unknown` means the photo couldn't be judged (no sky).
 */
#[Fillable(['area_id', 'ap_id', 'visibility', 'filename', 'origin_lat', 'origin_lon', 'heading', 'heading_source', 'scene', 'scene_score', 'obstruction', 'obstruction_kind', 'edited_by'])]
class GalleryImageDescription extends Model
{
    /** @use HasFactory<GalleryImageDescriptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'ap_id' => 'integer',
            'origin_lat' => 'float',
            'origin_lon' => 'float',
            'heading' => 'integer',
            'scene' => Scene::class,
            'scene_score' => 'float',
            'obstruction' => 'float',
        ];
    }
}

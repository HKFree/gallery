<?php

namespace App\Models;

use Database\Factories\GalleryImageEmbeddingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo's visual fingerprint (an image embedding computed in a manager's browser), used to
 * find photos of the same view, e.g. the same direction from an AP in another year or season.
 * The vector is stored as packed little-endian float32.
 */
#[Fillable(['area_id', 'ap_id', 'visibility', 'filename', 'model', 'vector'])]
class GalleryImageEmbedding extends Model
{
    /** @use HasFactory<GalleryImageEmbeddingFactory> */
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
        ];
    }

    /**
     * The vector as a list of floats.
     *
     * @return Attribute<list<float>, list<float>>
     */
    protected function vector(): Attribute
    {
        return Attribute::make(
            get: fn (string $packed): array => array_values(unpack('g*', $packed)),
            set: fn (array $values): string => pack('g*', ...$values),
        );
    }
}

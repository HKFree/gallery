<?php

namespace App\Models;

use Database\Factories\GalleryImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Index entry for one gallery image stored on disk; the disk stays the source of truth.
 *
 * All dates are wall-clock times in the gallery timezone (`services.gallery.timezone`), not
 * UTC: EXIF dates carry no zone, so the other dates are converted to match before saving.
 * The timeline orders by `sort_at` (taken, else client-modified, else uploaded) and groups by
 * `sort_month` (`Y-m`), both derived on save.
 */
#[Fillable(['area_id', 'ap_id', 'visibility', 'filename', 'taken_at', 'client_modified_at', 'uploaded_at'])]
class GalleryImage extends Model
{
    /** @use HasFactory<GalleryImageFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(fn (GalleryImage $image) => $image->syncSortDate());
    }

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
            'taken_at' => 'datetime',
            'client_modified_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'sort_at' => 'datetime',
        ];
    }

    /**
     * Derive `sort_at` and `sort_month` from the best available date.
     */
    public function syncSortDate(): void
    {
        $this->sort_at = $this->taken_at ?? $this->client_modified_at ?? $this->uploaded_at;
        $this->sort_month = $this->sort_at->format('Y-m');
    }
}

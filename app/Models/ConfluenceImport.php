<?php

namespace App\Models;

use App\Enums\ImportItemStatus;
use App\Enums\ImportStatus;
use Database\Factories\ConfluenceImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of importing a Confluence page's photos into a gallery.
 */
#[Fillable(['user_id', 'area_id', 'ap_id', 'visibility', 'page_id', 'page_title', 'page_version', 'page_url', 'status', 'error'])]
class ConfluenceImport extends Model
{
    /** @use HasFactory<ConfluenceImportFactory> */
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
            'page_id' => 'integer',
            'page_version' => 'integer',
            'status' => ImportStatus::class,
        ];
    }

    /**
     * @return HasMany<ConfluenceImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ConfluenceImportItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Imports into one gallery.
     *
     * @param  Builder<ConfluenceImport>  $query
     */
    public function scopeForGallery(Builder $query, string $visibility, int $areaId, int $apId): void
    {
        $query->where(['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId]);
    }

    /**
     * Number of items per status.
     *
     * @return array<string, int>
     */
    public function itemCounts(): array
    {
        $counts = $this->items()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(ImportItemStatus::cases())
            ->mapWithKeys(fn (ImportItemStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }
}

<?php

namespace App\Models;

use App\Enums\ImportItemStatus;
use Database\Factories\ConfluenceImportItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment considered by a Confluence import. `stored_filename` is saved as soon as the
 * file is stored, so a retry after a crash indexes it instead of downloading it again.
 */
#[Fillable(['attachment_id', 'attachment_version', 'original_filename', 'size', 'download_path', 'attachment_created_at', 'stored_filename', 'status', 'reason'])]
class ConfluenceImportItem extends Model
{
    /** @use HasFactory<ConfluenceImportItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachment_id' => 'integer',
            'attachment_version' => 'integer',
            'size' => 'integer',
            'attachment_created_at' => 'immutable_datetime',
            'status' => ImportItemStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ConfluenceImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ConfluenceImport::class, 'confluence_import_id');
    }
}

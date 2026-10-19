<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportRowStatus;
use App\Enums\ImportEntityType;
use Database\Factories\AssetImportRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One data row of an imported file (Phase 2.10 R6, SDD DD-96).
 *
 * `raw` holds the cells as read (by column index); `payload` the values that
 * would be saved, in the shape the create rules expect; `sources` where each
 * payload value came from (`file`, `mapping`, `correction`, `default`), so
 * `applied_fields` can be written truthfully at save time. `corrections` keeps
 * every value an administrator typed, with who and when.
 *
 * @property int $id
 * @property int $asset_import_id
 * @property int $row_number
 * @property ImportEntityType|null $entity_type
 * @property array<array-key, string>|null $raw
 * @property array<string, list<string>>|null $cell_flags
 * @property array<string, mixed>|null $payload
 * @property array<string, string>|null $sources
 * @property array<string, array<string, mixed>>|null $corrections
 * @property list<string>|null $acknowledged
 * @property list<array<string, mixed>>|null $issues
 * @property AssetImportRowStatus $status
 * @property bool $is_excluded
 * @property int|null $location_id
 * @property int|null $catalog_entry_id
 * @property string|null $search_text
 * @property AssetImportRowOutcome|null $outcome
 * @property string|null $failure_code
 * @property string|null $previous_failure_code
 * @property int|null $created_pc_unit_id
 * @property int|null $created_asset_id
 * @property int|null $created_room_id
 * @property list<string>|null $applied_fields
 * @property int $attempts
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetImportRow extends Model
{
    /** @use HasFactory<AssetImportRowFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entity_type' => ImportEntityType::class,
            'status' => AssetImportRowStatus::class,
            'outcome' => AssetImportRowOutcome::class,
            'raw' => 'array',
            'cell_flags' => 'array',
            'payload' => 'array',
            'sources' => 'array',
            'corrections' => 'array',
            'acknowledged' => 'array',
            'issues' => 'array',
            'applied_fields' => 'array',
            'is_excluded' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AssetImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AssetImport::class, 'asset_import_id');
    }

    /** @return BelongsTo<AssetImportLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetImportLocation::class, 'location_id');
    }

    /** @return BelongsTo<AssetImportCatalogEntry, $this> */
    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(AssetImportCatalogEntry::class, 'catalog_entry_id');
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function createdPcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class, 'created_pc_unit_id')->withTrashed();
    }

    /** @return BelongsTo<Asset, $this> */
    public function createdAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'created_asset_id')->withTrashed();
    }
}

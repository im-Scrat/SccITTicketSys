<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComponentType;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportCatalogMatch;
use App\Support\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One distinct catalog combination (category, brand, model) in an imported
 * file (Phase 2.10 R6, owner decision D5). A `new` combination is only a
 * proposal: it needs the administrator's approval, and is created only after
 * the import is confirmed — never during preview.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_import_id
 * @property string $normalized
 * @property ComponentType $component_type
 * @property string|null $manufacturer_name
 * @property string $model_name
 * @property string|null $model_number
 * @property ImportCatalogMatch $match
 * @property int|null $matched_model_id
 * @property list<array<string, mixed>>|null $suggestions
 * @property ImportCatalogDecision $decision
 * @property int|null $chosen_model_id
 * @property int|null $created_model_id
 * @property int $row_count
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetImportCatalogEntry extends Model
{
    use HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'match' => ImportCatalogMatch::class,
            'decision' => ImportCatalogDecision::class,
            'suggestions' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AssetImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AssetImport::class, 'asset_import_id');
    }

    /** @return HasMany<AssetImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(AssetImportRow::class, 'catalog_entry_id');
    }

    /** @return BelongsTo<HardwareModel, $this> */
    public function matchedModel(): BelongsTo
    {
        return $this->belongsTo(HardwareModel::class, 'matched_model_id')->withTrashed();
    }

    /** @return BelongsTo<HardwareModel, $this> */
    public function chosenModel(): BelongsTo
    {
        return $this->belongsTo(HardwareModel::class, 'chosen_model_id');
    }

    /**
     * The catalog model the rows will be saved against: the match, the model
     * the administrator chose, or the one created at confirm. Null while a new
     * entry is still a proposal.
     */
    public function resolvedModelId(): ?int
    {
        return match ($this->decision) {
            ImportCatalogDecision::Existing => $this->chosen_model_id,
            ImportCatalogDecision::Approved => $this->match === ImportCatalogMatch::Existing ? $this->matched_model_id : $this->created_model_id,
            default => null,
        };
    }
}

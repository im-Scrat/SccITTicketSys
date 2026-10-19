<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HardwareReplacementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $maintenance_record_id
 * @property int|null $pc_unit_id
 * @property int|null $old_component_id
 * @property int|null $old_asset_id
 * @property int|null $new_component_id
 * @property int|null $new_asset_id
 * @property int $quantity
 * @property string|null $replacement_reason
 * @property int|null $warranty_months
 * @property Carbon|null $replaced_at
 * @property-read HardwareComponent|null $oldComponent
 * @property-read HardwareComponent|null $newComponent
 * @property-read Asset|null $oldAsset
 * @property-read Asset|null $newAsset
 */
class HardwareReplacement extends Model
{
    /** @use HasFactory<HardwareReplacementFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'replaced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<HardwareComponent, $this> */
    public function oldComponent(): BelongsTo
    {
        return $this->belongsTo(HardwareComponent::class, 'old_component_id');
    }

    /** @return BelongsTo<HardwareComponent, $this> */
    public function newComponent(): BelongsTo
    {
        return $this->belongsTo(HardwareComponent::class, 'new_component_id');
    }

    /**
     * The serialized asset **removed** (SRS DR-022; SDD DD-56).
     *
     * Nullable: a replaced part is often not a registered serialized asset — a
     * fan, a thermal pad, a cable. When it *is* one, this is what lets the
     * replacement close the right `pc_component_installations` row instead of
     * guessing from component type, which breaks the moment a machine holds two
     * of the same part.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function oldAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'old_asset_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function newAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'new_asset_id');
    }
}

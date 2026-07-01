<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HardwareReplacementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<Asset, $this> */
    public function newAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'new_asset_id');
    }
}

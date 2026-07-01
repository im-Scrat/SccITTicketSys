<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\PcCondition;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'condition' => PcCondition::class,
            'purchase_price' => 'decimal:2',
            'purchase_date' => 'date',
            'warranty_expiration' => 'date',
        ];
    }

    /** @return BelongsTo<HardwareModel, $this> */
    public function hardwareModel(): BelongsTo
    {
        return $this->belongsTo(HardwareModel::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function currentRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'current_room_id');
    }

    /** @return HasMany<AssetStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(AssetStatusHistory::class);
    }

    /** @return HasMany<PcComponentInstallation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(PcComponentInstallation::class);
    }

    /** @return HasMany<AssetTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class);
    }
}

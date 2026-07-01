<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consumable extends Model
{
    /** @use HasFactory<ConsumableFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'is_active' => 'boolean',
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

    /** @return HasMany<StockTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }
}

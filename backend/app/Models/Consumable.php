<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $hardware_model_id
 * @property int|null $supplier_id
 * @property int|null $current_room_id
 * @property string $name
 * @property string $item_code
 * @property string $unit_of_measure
 * @property int $quantity_on_hand
 * @property int $reorder_level
 * @property string|null $unit_cost
 * @property string|null $notes
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RoomLayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomLayout extends Model
{
    /** @use HasFactory<RoomLayoutFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasMany<FloorPlanPosition, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(FloorPlanPosition::class);
    }
}

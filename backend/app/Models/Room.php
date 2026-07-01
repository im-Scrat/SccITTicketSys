<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomType;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'room_type' => RoomType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return HasMany<RoomLayout, $this> */
    public function layouts(): HasMany
    {
        return $this->hasMany(RoomLayout::class);
    }

    /** @return HasMany<PcUnit, $this> */
    public function pcUnits(): HasMany
    {
        return $this->hasMany(PcUnit::class);
    }
}

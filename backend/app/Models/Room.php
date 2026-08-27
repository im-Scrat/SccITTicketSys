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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $floor_id
 * @property RoomType $room_type
 * @property string $name
 * @property string $code
 * @property string|null $room_number
 * @property int|null $capacity
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int|null $pc_units_count
 * @property-read int|null $assets_count
 * @property-read int|null $consumables_count
 * @property-read int|null $tickets_count
 */
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

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'current_room_id');
    }

    /** @return HasMany<Consumable, $this> */
    public function consumables(): HasMany
    {
        return $this->hasMany(Consumable::class, 'current_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return MorphMany<ActivityLog, $this> */
    public function activityAbout(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }
}

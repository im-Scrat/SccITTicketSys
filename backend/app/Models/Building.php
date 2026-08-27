<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string|null $address
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int|null $floors_count
 * @property-read int|null $rooms_count
 */
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Floor, $this> */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class);
    }

    /** @return HasManyThrough<Room, Floor, $this> */
    public function rooms(): HasManyThrough
    {
        return $this->hasManyThrough(Room::class, Floor::class);
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

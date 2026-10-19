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
            'revision' => 'integer',
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

    /**
     * Desks, walls, doors… (Phase 2.11, SDD DD-102).
     *
     * @return HasMany<FloorPlanFixture, $this>
     */
    public function fixtures(): HasMany
    {
        return $this->hasMany(FloorPlanFixture::class);
    }

    /**
     * Who last saved this layout — named (never identified) in the editor and
     * its broadcasts.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FloorPlanFixtureKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A layout-only shape on a room's Floorplan — desk, wall, door… (Phase 2.11,
 * SDD DD-102). Identified by a client-generated `uuid`, unique per layout.
 *
 * @property int $id
 * @property string $uuid
 * @property int $room_layout_id
 * @property FloorPlanFixtureKind $kind
 * @property string $pos_x
 * @property string $pos_y
 * @property string $width
 * @property string $height
 * @property string $rotation
 * @property string|null $label
 */
class FloorPlanFixture extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FloorPlanFixtureKind::class,
            'pos_x' => 'decimal:2',
            'pos_y' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'rotation' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<RoomLayout, $this>
     */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(RoomLayout::class, 'room_layout_id');
    }
}

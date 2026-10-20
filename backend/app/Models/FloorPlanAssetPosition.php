<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a confirmed asset stands on a room layout (SDD DD-115) — the
 * equipment counterpart of {@see FloorPlanPosition}, kept in its own table so
 * the PC positions' invariants are untouched. Written only by SaveRoomLayout.
 */
class FloorPlanAssetPosition extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pos_x' => 'decimal:2',
            'pos_y' => 'decimal:2',
            'rotation' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<RoomLayout, $this> */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(RoomLayout::class, 'room_layout_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

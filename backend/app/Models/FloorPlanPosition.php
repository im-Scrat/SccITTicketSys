<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FloorPlanPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FloorPlanPosition extends Model
{
    /** @use HasFactory<FloorPlanPositionFactory> */
    use HasFactory;

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

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }
}

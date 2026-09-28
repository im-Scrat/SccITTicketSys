<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\DTOs;

use App\Domains\FloorPlan\Services\FloorPlanService;
use App\Models\FloorPlanPosition;
use App\Models\Room;
use App\Models\RoomLayout;
use Illuminate\Support\Collection;

/**
 * What the map needs for one room, assembled by {@see FloorPlanService}.
 *
 * A plain carrier: by the time one exists, authorization has already happened,
 * and every position in it has been checked against the layout's own room.
 */
final readonly class RoomPlan
{
    /**
     * @param  Collection<int, FloorPlanPosition>  $positions  positioned PC units, each with `pcUnit` loaded
     * @param  int  $unplacedCount  live PC units in the room that have no position on this layout
     */
    public function __construct(
        public Room $room,
        public ?RoomLayout $layout,
        public Collection $positions,
        public int $unplacedCount,
    ) {}
}

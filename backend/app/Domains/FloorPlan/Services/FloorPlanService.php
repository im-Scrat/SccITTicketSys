<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use App\Domains\FloorPlan\DTOs\RoomPlan;
use App\Models\FloorPlanPosition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Read-model assembly for the floor-plan map (SDD §25). Read-only: nothing here
 * creates, moves or activates anything — placement is `PlacePcUnit`.
 *
 * Authorization, room resolution and the active-layout lookup are all
 * {@see RoomLayoutService}'s — this class adds no second copy of that logic. It
 * adds the one thing the map needs that the service does not: the positioned
 * PC units, filtered so that nothing outside the room can appear.
 */
class FloorPlanService
{
    public function __construct(
        private readonly RoomLayoutService $layouts,
        private readonly FloorPlanDefaults $defaults,
    ) {}

    /**
     * The active layout of a room and the PC units placed on it.
     *
     * A room with no active layout is not an error — the map shows an empty
     * state — so it yields a plan whose `layout` is null.
     *
     * @throws AuthorizationException when the actor may not open the floor plan
     * @throws ModelNotFoundException when the room does not exist (or is archived)
     */
    public function roomPlan(User $actor, string $roomUuid): RoomPlan
    {
        $room = $this->layouts->room($actor, $roomUuid)->load('floor.building');
        $layout = $this->layouts->activeLayout($actor, $room);

        $liveUnits = $room->pcUnits()->count();

        if ($layout === null) {
            return new RoomPlan($room, null, collect(), $liveUnits);
        }

        // A position row is only trusted while its PC still lives in this room.
        // The foreign keys cannot say so: a PC transferred to another room keeps
        // its old position row, and an archived PC keeps its too. Filtering on
        // the PC's *current* room (and its soft-delete scope, which `whereHas`
        // applies) is what stops a map from showing — or leaking the name and
        // status of — a machine that is no longer in the room.
        $positions = FloorPlanPosition::query()
            ->where('room_layout_id', $layout->getKey())
            ->whereHas('pcUnit', fn ($units) => $units->where('room_id', $layout->room_id))
            ->with('pcUnit')
            ->orderBy('z_index')
            ->orderBy('id')
            ->get();

        // The units an editor could still place: live in this room, with no
        // trusted position on this layout. Same room and soft-delete scoping as
        // the positions above, so nothing from outside the room can appear.
        $unplaced = $room->pcUnits()
            ->whereNotIn('id', $positions->pluck('pc_unit_id'))
            ->orderBy('pc_name')
            ->orderBy('id')
            ->get();

        return new RoomPlan(
            $room,
            $layout,
            $positions,
            $unplaced->count(),
            $unplaced,
            // The same policy ability the write route and `PlacePcUnit` check,
            // so the editor is offered exactly when a write would be accepted.
            // `activeLayout()` only ever returns an active layout, which is the
            // other half of "editable".
            Gate::forUser($actor)->allows('manage', FloorPlanPosition::class),
            $this->defaults->snapToGrid(),
        );
    }
}

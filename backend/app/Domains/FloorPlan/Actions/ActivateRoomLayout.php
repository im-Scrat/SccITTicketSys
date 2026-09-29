<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Actions;

use App\Domains\FloorPlan\Services\RoomLayoutService;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Switch a room's active layout to a specific version (SRS FR-FP-001).
 *
 * **Serialized on the room, not on the two rows individually.** Deactivating
 * whichever layout is currently active and activating the target are two
 * statements; without a lock spanning both, two concurrent activations for the
 * same room could each deactivate the (same, or by-then-different) current
 * layout and then both try to activate their own target, and only one would
 * survive `room_layouts_one_active_per_room` — as a raw constraint-violation
 * 500, not a clean refusal. Locking the room row first serializes the whole
 * operation the same way {@see CreateRoomLayout} serializes version numbering.
 *
 * **No positions are copied.** Position history is preserved by leaving the
 * previous layout's `floor_plan_positions` rows exactly where they are — a
 * newly activated layout starts with none, and `FloorPlanService` already
 * reports every live unit of the room as unplaced until an administrator
 * places it. Nothing in SRS FR-FP-008 asks for automatic carry-over, and
 * inventing it here would be a second, undocumented placement policy.
 */
final class ActivateRoomLayout
{
    public function __construct(
        private readonly RoomLayoutService $layouts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not manage the floor plan
     * @throws ModelNotFoundException when the room or version does not exist
     */
    public function handle(User $actor, string $roomUuid, int $version, ?Request $request = null): RoomLayout
    {
        $this->layouts->authorizeManage($actor);
        $room = $this->layouts->room($actor, $roomUuid);
        $layout = $this->layouts->layout($actor, $room, $version);

        if ($layout->is_active) {
            // Already the active layout — a no-op, not an error, the same
            // stance TicketLifecycle/MaintenanceLifecycle take on a
            // resubmitted current state.
            return $layout;
        }

        $activated = DB::transaction(function () use ($room, $layout, $actor): RoomLayout {
            Room::query()->whereKey($room->getKey())->lockForUpdate()->first();

            RoomLayout::query()
                ->where('room_id', $room->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_by' => $actor->getKey()]);

            $layout->forceFill(['is_active' => true, 'updated_by' => $actor->getKey()])->save();

            return $layout->refresh();
        });

        $this->audit->activity(
            ActivityAction::LayoutActivated,
            actor: $actor,
            subject: $activated,
            properties: ['version' => $activated->version],
            request: $request,
            module: 'floor-plan',
            description: "Layout v{$activated->version} activated for {$room->name}",
        );

        return $activated;
    }
}

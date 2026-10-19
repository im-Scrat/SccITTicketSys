<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Actions;

use App\Domains\FloorPlan\Events\RoomPlanChanged;
use App\Domains\FloorPlan\Services\FloorPlanDefaults;
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
 * Start a new version of a room's layout (SRS FR-FP-001/008).
 *
 * **Inactive unless asked.** `room_layouts.is_active` defaults to `true` at the
 * schema level, which this action deliberately overrides — creating a new
 * version and switching viewers to it are separate decisions
 * ({@see ActivateRoomLayout}). Phase 2.11 adds `$activate` for the one case
 * where they are not: a room's first Floorplan, set up from the editor's empty
 * state, is created and activated in the same transaction (under the same
 * room lock), so there is never a half-set-up room.
 *
 * **Versioning is race-safe without locking an aggregate.** Postgres refuses
 * `SELECT MAX(...) ... FOR UPDATE` (aggregates and row locks do not mix), so
 * concurrent creation for the same room is serialized by locking the *room*
 * row instead — any other request creating a layout for this room blocks on
 * the same lock until this transaction ends, so the `MAX(version)+1` read
 * that follows is never racing another one.
 */
final class CreateRoomLayout
{
    public function __construct(
        private readonly RoomLayoutService $layouts,
        private readonly FloorPlanDefaults $defaults,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not manage the floor plan
     * @throws ModelNotFoundException when the room does not exist (or is archived)
     */
    public function handle(
        User $actor,
        string $roomUuid,
        int $width,
        int $height,
        ?int $gridSize = null,
        ?string $backgroundImage = null,
        ?Request $request = null,
        bool $activate = false,
    ): RoomLayout {
        $this->layouts->authorizeManage($actor);
        $room = $this->layouts->room($actor, $roomUuid);

        $layout = DB::transaction(function () use ($room, $width, $height, $gridSize, $backgroundImage, $actor, $activate): RoomLayout {
            Room::query()->whereKey($room->getKey())->lockForUpdate()->first();

            $nextVersion = (int) (RoomLayout::query()->where('room_id', $room->getKey())->max('version')) + 1;

            if ($activate) {
                RoomLayout::query()
                    ->where('room_id', $room->getKey())
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_by' => $actor->getKey()]);
            }

            return RoomLayout::query()->create([
                'room_id' => $room->getKey(),
                'version' => $nextVersion,
                'width' => $width,
                'height' => $height,
                'grid_size' => $gridSize ?? $this->defaults->gridSize(),
                'background_image' => $backgroundImage,
                'is_active' => $activate,
                'revision' => 1,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });

        $this->audit->activity(
            ActivityAction::LayoutCreated,
            actor: $actor,
            subject: $layout,
            properties: ['version' => $layout->version, 'width' => $width, 'height' => $height, 'grid_size' => $layout->grid_size],
            request: $request,
            module: 'floor-plan',
            description: "Layout v{$layout->version} created for {$room->name}",
        );

        if ($activate) {
            $this->audit->activity(
                ActivityAction::LayoutActivated,
                actor: $actor,
                subject: $layout,
                properties: ['version' => $layout->version],
                request: $request,
                module: 'floor-plan',
                description: "Layout v{$layout->version} activated for {$room->name}",
            );

            RoomPlanChanged::dispatch($room->uuid, $layout->version, $layout->revision, 'layout_activated', $actor->fullName());
        }

        return $layout;
    }
}

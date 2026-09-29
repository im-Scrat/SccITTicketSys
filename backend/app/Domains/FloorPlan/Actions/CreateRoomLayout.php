<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Actions;

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
 * **Never active on creation.** `room_layouts.is_active` defaults to `true` at
 * the schema level, which this action deliberately overrides — creating and
 * activating are two separately authorized operations ({@see ActivateRoomLayout}),
 * matching the client-visible flow: draw the new canvas, place things on it
 * (once it is active), then switch viewers over to it, in that order.
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
    ): RoomLayout {
        $this->layouts->authorizeManage($actor);
        $room = $this->layouts->room($actor, $roomUuid);

        $layout = DB::transaction(function () use ($room, $width, $height, $gridSize, $backgroundImage, $actor): RoomLayout {
            Room::query()->whereKey($room->getKey())->lockForUpdate()->first();

            $nextVersion = (int) (RoomLayout::query()->where('room_id', $room->getKey())->max('version')) + 1;

            return RoomLayout::query()->create([
                'room_id' => $room->getKey(),
                'version' => $nextVersion,
                'width' => $width,
                'height' => $height,
                'grid_size' => $gridSize ?? $this->defaults->gridSize(),
                'background_image' => $backgroundImage,
                'is_active' => false,
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

        return $layout;
    }
}

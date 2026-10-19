<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\RoomType;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create a room on a floor (SRS FR-LOC-003). `capacity` is validated ≥ 0 in the
 * FormRequest and guarded by the `rooms_capacity_check` database constraint.
 */
class CreateRoom
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Floor $floor, array $data, User $actor, Request $request): Room
    {
        $type = RoomType::from((string) ($data['room_type'] ?? RoomType::Laboratory->value));

        $room = DB::transaction(fn (): Room => Room::create([
            'floor_id' => $floor->getKey(),
            'room_type' => $type->value,
            'name' => $data['name'],
            'code' => $data['code'],
            'room_number' => $data['room_number'] ?? null,
            'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]));

        $room->load('floor.building');

        $this->audit->activity(
            ActivityAction::LocationCreated,
            actor: $actor,
            subject: $room,
            properties: [
                'building' => $room->floor?->building?->name,
                'floor' => $room->floor?->name,
                'name' => $room->name,
                'code' => $room->code,
                'room_type' => $type->value,
            ],
            request: $request,
            module: 'locations',
            description: "Room {$room->name} created",
        );

        return $room;
    }
}

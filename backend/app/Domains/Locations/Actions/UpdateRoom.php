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
 * Update a room (SRS FR-LOC-003), including moving it to another floor — a
 * genuine operation when a site is re-surveyed. The move is recorded in the
 * audit properties so the room's history explains where it used to be.
 */
class UpdateRoom
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Room $room, array $data, User $actor, Request $request, ?Floor $floor = null): Room
    {
        $room->loadMissing('floor.building');
        $changes = [];

        if ($floor !== null && $floor->getKey() !== $room->floor_id) {
            $floor->loadMissing('building');
            $changes['floor'] = [
                'from' => $room->floor?->name,
                'to' => $floor->name,
                'from_building' => $room->floor?->building?->name,
                'to_building' => $floor->building?->name,
            ];
        }

        if (array_key_exists('room_type', $data)) {
            $type = RoomType::from((string) $data['room_type']);

            if ($type !== $room->room_type) {
                $changes['room_type'] = ['from' => $room->room_type->value, 'to' => $type->value];
            }
        }

        foreach (['name', 'code', 'room_number', 'description'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $room->{$field}) {
                $changes[$field] = ['from' => $room->{$field}, 'to' => $data[$field]];
            }
        }

        if (array_key_exists('capacity', $data)) {
            $capacity = $data['capacity'] !== null ? (int) $data['capacity'] : null;

            if ($capacity !== ($room->capacity !== null ? (int) $room->capacity : null)) {
                $changes['capacity'] = ['from' => $room->capacity, 'to' => $capacity];
            }
        }

        DB::transaction(function () use ($room, $data, $actor, $floor): void {
            $room->fill([
                'floor_id' => $floor?->getKey() ?? $room->floor_id,
                'room_type' => isset($data['room_type'])
                    ? RoomType::from((string) $data['room_type'])->value
                    : $room->room_type->value,
                'name' => $data['name'] ?? $room->name,
                'code' => $data['code'] ?? $room->code,
                'room_number' => $data['room_number'] ?? null,
                'capacity' => array_key_exists('capacity', $data) && $data['capacity'] !== null
                    ? (int) $data['capacity']
                    : null,
                'description' => $data['description'] ?? null,
                'updated_by' => $actor->getKey(),
            ]);

            $room->save();
        });

        $this->audit->activity(
            ActivityAction::LocationUpdated,
            actor: $actor,
            subject: $room,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'locations',
            description: "Room {$room->name} updated",
        );

        return $room->refresh()->load('floor.building');
    }
}

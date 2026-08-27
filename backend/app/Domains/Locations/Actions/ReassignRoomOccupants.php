<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Move a room's live occupants to another room (SRS FR-LOC-004, "…or reassign
 * them first"). This is the escape hatch that lets an administrator retire a
 * location without stranding equipment: PC units, serialized assets and
 * consumable stock all move in one transaction.
 *
 * **Tickets are deliberately not moved.** A ticket's `room_id` records where the
 * problem was reported, so rewriting it would falsify history. Open tickets must
 * be resolved or closed before their room can be archived — the blocker message
 * says so explicitly.
 */
class ReassignRoomOccupants
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{pc_units: int, assets: int, consumables: int}
     */
    public function handle(Room $from, Room $to, User $actor, Request $request): array
    {
        $moved = DB::transaction(function () use ($from, $to, $actor): array {
            $pcUnits = PcUnit::query()
                ->where('room_id', $from->getKey())
                ->update(['room_id' => $to->getKey(), 'updated_by' => $actor->getKey()]);

            $assets = Asset::query()
                ->where('current_room_id', $from->getKey())
                ->update(['current_room_id' => $to->getKey(), 'updated_by' => $actor->getKey()]);

            $consumables = Consumable::query()
                ->where('current_room_id', $from->getKey())
                ->update(['current_room_id' => $to->getKey(), 'updated_by' => $actor->getKey()]);

            return [
                'pc_units' => $pcUnits,
                'assets' => $assets,
                'consumables' => $consumables,
            ];
        });

        $from->loadMissing('floor.building');
        $to->loadMissing('floor.building');

        $properties = [
            'from' => ['id' => $from->uuid, 'name' => $from->name],
            'to' => ['id' => $to->uuid, 'name' => $to->name],
            'moved' => $moved,
        ];

        // Recorded against both rooms so either timeline tells the whole story.
        $this->audit->activity(
            ActivityAction::LocationOccupantsReassigned,
            actor: $actor,
            subject: $from,
            properties: $properties,
            request: $request,
            module: 'locations',
            description: "Occupants moved from {$from->name} to {$to->name}",
        );

        $this->audit->activity(
            ActivityAction::LocationOccupantsReassigned,
            actor: $actor,
            subject: $to,
            properties: $properties,
            request: $request,
            module: 'locations',
            description: "Occupants received from {$from->name}",
        );

        return $moved;
    }
}

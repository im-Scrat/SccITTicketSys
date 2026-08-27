<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Locations\Exceptions\LocationInUseException;
use App\Domains\Locations\Services\LocationArchiver;
use App\Domains\Locations\Services\LocationGuard;
use App\Enums\ActivityAction;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Archive (soft delete) a building, floor or room with its subtree
 * (SRS FR-LOC-004, BR-12 — business entities are never hard-deleted).
 *
 * Two guarantees:
 *  1. **Nothing is stranded.** LocationGuard is consulted first; if the subtree
 *     still holds live PC units, assets, stock or open tickets the archive is
 *     refused with a 422 blocker report. This repeats the Policy's check on
 *     purpose — authorization and the write path each enforce the invariant
 *     (defense in depth, as in Phase 2.3).
 *  2. **The cascade is reversible.** LocationArchiver stamps the whole subtree
 *     with one `deleted_at` receipt so {@see RestoreLocation} can reverse exactly
 *     these rows and nothing else (SDD DD-27).
 */
class ArchiveLocation
{
    public function __construct(
        private readonly LocationGuard $guard,
        private readonly LocationArchiver $archiver,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Building|Floor|Room $location, User $actor, Request $request): Building|Floor|Room
    {
        [$level, $blockers, $roomIds] = match (true) {
            $location instanceof Building => [
                'building',
                $this->guard->buildingBlockers($location),
                $this->guard->roomIdsForBuildings([$location->getKey()]),
            ],
            $location instanceof Floor => [
                'floor',
                $this->guard->floorBlockers($location),
                $this->guard->roomIdsForFloors([$location->getKey()]),
            ],
            default => [
                'room',
                $this->guard->roomBlockers($location),
                [$location->getKey()],
            ],
        };

        if (array_sum($blockers) > 0) {
            throw new LocationInUseException($level, $blockers, $this->guard->breakdown($roomIds));
        }

        $cascade = match (true) {
            $location instanceof Building => $this->archiver->archiveBuilding($location),
            $location instanceof Floor => $this->archiver->archiveFloor($location),
            default => $this->archiveRoom($location),
        };

        $this->audit->activity(
            ActivityAction::LocationArchived,
            actor: $actor,
            subject: $location,
            properties: [
                'level' => $level,
                'name' => $location->name,
                'cascaded_floors' => $cascade['floors'],
                'cascaded_rooms' => $cascade['rooms'],
            ],
            request: $request,
            module: 'locations',
            description: ucfirst($level)." {$location->name} archived",
        );

        return $location;
    }

    /**
     * A room has no descendants, so its archive is a plain soft delete — but it
     * still runs in a transaction for symmetry with the cascading levels.
     *
     * @return array{floors: int, rooms: int}
     */
    private function archiveRoom(Room $room): array
    {
        DB::transaction(function () use ($room): void {
            $room->delete();
        });

        return ['floors' => 0, 'rooms' => 0];
    }
}

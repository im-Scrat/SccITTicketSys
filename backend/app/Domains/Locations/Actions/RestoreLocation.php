<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Locations\Services\LocationArchiver;
use App\Enums\ActivityAction;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Restore an archived building, floor or room (SRS FR-LOC-004).
 *
 * Reverses **exactly** the rows that were archived with this location — the
 * cascade receipt is the shared `deleted_at` stamp (SDD DD-27). A room that was
 * archived on its own before its building was archived keeps its own stamp and
 * therefore stays archived when the building comes back: restoring a parent
 * never silently resurrects a child that was retired for its own reasons.
 *
 * Restoring a child whose parent is still archived is allowed — the row returns,
 * but the location remains unavailable in pickers until the parent is restored
 * too, which the `selectable` flag on the room resource reports.
 */
class RestoreLocation
{
    public function __construct(
        private readonly LocationArchiver $archiver,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Building|Floor|Room $location, User $actor, Request $request): Building|Floor|Room
    {
        [$level, $cascade] = match (true) {
            $location instanceof Building => ['building', $this->archiver->restoreBuilding($location)],
            $location instanceof Floor => ['floor', $this->archiver->restoreFloor($location)],
            default => ['room', $this->restoreRoom($location)],
        };

        $this->audit->activity(
            ActivityAction::LocationRestored,
            actor: $actor,
            subject: $location,
            properties: [
                'level' => $level,
                'name' => $location->name,
                'restored_floors' => $cascade['floors'],
                'restored_rooms' => $cascade['rooms'],
            ],
            request: $request,
            module: 'locations',
            description: ucfirst($level)." {$location->name} restored",
        );

        return $location->refresh();
    }

    /**
     * @return array{floors: int, rooms: int}
     */
    private function restoreRoom(Room $room): array
    {
        DB::transaction(function () use ($room): void {
            $room->restore();
        });

        return ['floors' => 0, 'rooms' => 0];
    }
}

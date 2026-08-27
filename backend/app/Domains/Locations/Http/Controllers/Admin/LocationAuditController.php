<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers\Admin;

use App\Domains\Identity\Http\Resources\ActivityLogResource;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-record audit timeline for a building, floor or room (SRS FR-AUD-003/004):
 * every create, update, activate/deactivate, archive/restore and occupant
 * reassignment recorded against that location, newest first.
 *
 * Reuses the Identity ActivityLogResource — the audit vocabulary is shared
 * platform-wide (SDD §16); it moves to a common audit namespace with the
 * Administration audit viewers (WP-2.9).
 */
class LocationAuditController extends Controller
{
    public function building(Request $request, Building $building): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $building);

        return $this->timeline($building);
    }

    public function floor(Request $request, Floor $floor): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $floor);

        return $this->timeline($floor);
    }

    public function room(Request $request, Room $room): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $room);

        return $this->timeline($room);
    }

    private function timeline(Model $subject): AnonymousResourceCollection
    {
        $logs = ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ActivityLogResource::collection($logs);
    }
}

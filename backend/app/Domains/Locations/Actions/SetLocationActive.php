<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Locations\Services\LocationOptions;
use App\Enums\ActivityAction;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Activate or deactivate a building or room (SRS FR-LOC-001/003).
 *
 * Deactivation is the reversible, non-destructive half of taking a location out
 * of service: the row and all of its history stay intact, but the location stops
 * being offered as location context anywhere in the platform — and deactivating
 * a building removes its whole subtree from every picker
 * ({@see LocationOptions}). Nothing is archived,
 * so no in-use check applies.
 *
 * Floors carry no `is_active` flag; a floor's availability derives from its
 * building (FR-LOC-002 requires no per-floor flag).
 */
class SetLocationActive
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Building|Room $location, bool $active, User $actor, Request $request): Building|Room
    {
        DB::transaction(function () use ($location, $active, $actor): void {
            $location->is_active = $active;
            $location->updated_by = $actor->getKey();
            $location->save();
        });

        $level = $location instanceof Building ? 'Building' : 'Room';

        $this->audit->activity(
            $active ? ActivityAction::LocationActivated : ActivityAction::LocationDeactivated,
            actor: $actor,
            subject: $location,
            properties: ['level' => strtolower($level), 'name' => $location->name],
            request: $request,
            module: 'locations',
            description: "{$level} {$location->name} ".($active ? 'activated' : 'deactivated'),
        );

        return $location->refresh();
    }
}

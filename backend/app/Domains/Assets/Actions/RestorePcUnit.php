<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\FloorPlan\Services\ReleasePcUnitPositions;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bring an archived PC unit back into the register (SRS FR-PC-001).
 *
 * Its specification, installations, tickets and maintenance history were never
 * touched by the archive, so restoring simply makes the machine visible again
 * with everything intact.
 */
class RestorePcUnit
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReleasePcUnitPositions $floorplans,
    ) {}

    public function handle(PcUnit $pcUnit, User $actor, Request $request): PcUnit
    {
        DB::transaction(function () use ($pcUnit, $actor): void {
            $pcUnit->restore();
            $pcUnit->forceFill(['updated_by' => $actor->getKey()])->save();
        });

        $this->audit->activity(
            ActivityAction::PcUnitRestored,
            actor: $actor,
            subject: $pcUnit,
            properties: [
                'unit_code' => $pcUnit->unit_code,
                'pc_name' => $pcUnit->pc_name,
                'status' => $pcUnit->status->value,
            ],
            request: $request,
            module: 'assets',
            description: "PC unit {$pcUnit->unit_code} restored",
        );

        $restored = $pcUnit->refresh()->load(['room.floor.building', 'specification']);

        // Phase 2.11 (DD-104): archiving released its Floorplan place, so it
        // returns unplaced — its room's editors only need to know it is back.
        $this->floorplans->announceArrival($restored->room, $actor);

        return $restored;
    }
}

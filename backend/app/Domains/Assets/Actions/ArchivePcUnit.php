<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\AssetInUseException;
use App\Domains\Assets\Services\AssetGuard;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Archive (soft delete) a PC unit (SRS FR-PC-001, BR-12).
 *
 * Refused with a **422 blocker report** while the machine still holds installed
 * components or has open tickets: archiving it would strand serialized assets
 * inside a machine nobody can see, which is the PC-level version of the
 * occupant-stranding problem FR-LOC-004 guards against for rooms.
 *
 * History survives: the soft delete is an `UPDATE`, so installations, tickets,
 * maintenance records and QR scan logs keep their foreign keys intact.
 */
class ArchivePcUnit
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssetGuard $guard,
    ) {}

    /**
     * @throws AssetInUseException
     */
    public function handle(PcUnit $pcUnit, User $actor, Request $request): PcUnit
    {
        $blockers = $this->guard->pcUnitBlockers($pcUnit);

        if (array_sum($blockers) > 0) {
            throw new AssetInUseException('pc_unit', $blockers, [
                'pc_unit' => [
                    'id' => $pcUnit->uuid,
                    'name' => $pcUnit->pc_name,
                    'unit_code' => $pcUnit->unit_code,
                ],
            ]);
        }

        DB::transaction(function () use ($pcUnit, $actor): void {
            $pcUnit->forceFill(['updated_by' => $actor->getKey()])->save();
            $pcUnit->delete();
        });

        $this->audit->activity(
            ActivityAction::PcUnitArchived,
            actor: $actor,
            subject: $pcUnit,
            properties: [
                'unit_code' => $pcUnit->unit_code,
                'pc_name' => $pcUnit->pc_name,
                'status' => $pcUnit->status->value,
            ],
            request: $request,
            module: 'assets',
            description: "PC unit {$pcUnit->unit_code} archived",
        );

        return $pcUnit;
    }
}

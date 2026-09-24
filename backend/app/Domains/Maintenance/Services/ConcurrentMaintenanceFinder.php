<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Domains\Tickets\Services\DuplicateFinder;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Warns that a machine already has maintenance in flight (Client decision,
 * 2026-08-28 — the WP-2.6 half of SRS FR-MNT-012).
 *
 * **A warning, deliberately not a constraint.** A unique index would be the
 * obvious implementation and the wrong one: a machine may legitimately carry a
 * scheduled preventive visit *and* an active corrective repair at the same
 * time, and those are two different jobs that must both exist. What actually
 * goes wrong is two technicians opening the same job without knowing about each
 * other, and the fix for that is telling them — not refusing the second record
 * and forcing whoever is right to work around the system.
 *
 * Hard idempotency belongs to WP-2.6b, where it is well posed: a *scan* is a
 * single physical event, so re-submitting one must update the same record
 * rather than create a second (FR-MNT-012, SDD DD-50). There is no equivalent
 * identity for a hand-created record, which is exactly why this is advisory.
 *
 * Shaped like {@see DuplicateFinder}: it answers with the rows it found and
 * lets the caller decide what to do about them.
 */
class ConcurrentMaintenanceFinder
{
    private const MAX_RESULTS = 5;

    /**
     * Open records already targeting the same machine.
     *
     * Returned rows are **not** visibility-scoped, and that is intentional: the
     * point is to warn about work the caller may not be able to see. The shape
     * below is therefore deliberately thin — a title, a status, a date and who
     * holds it — and carries no diagnosis, resolution, cost or evidence. A
     * warning that leaked another technician's findings would be a disclosure
     * dressed up as a courtesy.
     *
     * @return list<array<string, mixed>>
     */
    public function forTarget(?PcUnit $pcUnit, ?Asset $asset, ?MaintenanceRecord $excluding = null): array
    {
        if ($pcUnit === null && $asset === null) {
            return [];
        }

        $query = MaintenanceRecord::query()
            ->with(['type:id,name,slug', 'technician:id,uuid,first_name,last_name'])
            ->whereIn('status', array_map(
                static fn ($status): string => $status->value,
                MaintenanceVisibility::OPEN_STATUSES,
            ))
            ->where(function (Builder $q) use ($pcUnit, $asset): void {
                if ($pcUnit !== null) {
                    $q->orWhere('pc_unit_id', $pcUnit->getKey());
                }

                if ($asset !== null) {
                    $q->orWhere('asset_id', $asset->getKey());
                }
            });

        if ($excluding !== null) {
            $query->whereKeyNot($excluding->getKey());
        }

        return $query
            ->orderBy('scheduled_for')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(static fn (MaintenanceRecord $row): array => [
                'id' => $row->uuid,
                'title' => $row->title,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'type' => $row->type?->name,
                'technician' => $row->technician?->fullName(),
                'scheduled_for' => $row->scheduled_for?->toIso8601String(),
            ])
            ->all();
    }
}

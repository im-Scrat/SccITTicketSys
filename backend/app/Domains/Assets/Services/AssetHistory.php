<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Enums\ActivityAction;
use App\Enums\AssetStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\AssetTransfer;
use App\Models\MaintenanceRecord;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The unified asset timeline (SRS FR-AST-005, FR-PC-006).
 *
 * An asset's story is spread across seven tables, each written by a different
 * workflow. This service reads them all and merges them into **one chronological
 * stream**, so "what has happened to this machine?" is a single answer rather
 * than seven tabs the reader has to interleave by hand:
 *
 *   activity_logs             creation, edits, assignment, archive/restore, QR
 *   asset_status_history      every lifecycle transition, with actor and reason
 *   asset_transfers           room-to-room moves
 *   pc_component_installations  fitted into / removed from a PC
 *   maintenance_records       service visits
 *   tickets                   faults reported against the host PC
 *   qr_codes                  label issue and revocation
 *
 * **Strictly read-only, and strictly additive.** Nothing here updates or deletes
 * a historical row; the sources are append-only or soft-deleted by design, so a
 * record never disappears from the timeline. Soft-deleted parents are read with
 * `withTrashed()` for exactly that reason — archiving a PC must not erase the
 * fact that this asset once lived inside it.
 *
 * **Why merge in PHP.** The sources have incompatible shapes and no common
 * ancestor table, so a SQL `UNION` would need seven casts to a lowest common
 * denominator and would still not carry the per-source detail the UI renders.
 * The set is bounded by one asset's own lifetime — tens to hundreds of rows, not
 * millions — and each source is additionally capped by {@see PER_SOURCE_CAP}, so
 * the merge is O(small) and the query count is fixed at seven regardless of size.
 */
class AssetHistory
{
    /**
     * Per-source row cap. A single asset accumulating more than this in one
     * category is pathological; the cap keeps a runaway source from dominating
     * the merge, and the dedicated tabs (maintenance, transfers) show the
     * complete list for their own source.
     */
    private const PER_SOURCE_CAP = 250;

    public const DEFAULT_PER_PAGE = 25;

    /**
     * The merged, newest-first timeline for an asset, paginated.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forAsset(Asset $asset, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $entries = $this->collect(
            $this->activityEntries($asset),
            $this->statusEntries($asset),
            $this->transferEntries($asset),
            $this->installationEntries($asset),
            $this->maintenanceEntries($asset),
            $this->qrEntries($asset),
        );

        return $this->paginate($entries, $page, $perPage);
    }

    /**
     * The merged timeline for a PC unit. Its sources differ from an asset's —
     * a PC has tickets and fitted components, but no transfers of its own.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forPcUnit(PcUnit $pcUnit, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $entries = $this->collect(
            $this->activityEntries($pcUnit),
            $this->pcInstallationEntries($pcUnit),
            $this->pcMaintenanceEntries($pcUnit),
            $this->ticketEntries($pcUnit),
            $this->qrEntries($pcUnit),
        );

        return $this->paginate($entries, $page, $perPage);
    }

    /* ------------------------------------------------------------ sources */

    /**
     * Everything the AuditLogger recorded against this record — creation, field
     * edits with old→new values, technician assignment, archive/restore.
     *
     * @return list<array<string, mixed>>
     */
    private function activityEntries(Asset|PcUnit $subject): array
    {
        return ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(fn (ActivityLog $log): array => $this->entry(
                id: 'activity-'.$log->getKey(),
                type: 'activity',
                action: (string) $log->action,
                label: $this->actionLabel((string) $log->action),
                description: $log->description,
                actor: $log->user,
                at: $log->created_at?->toIso8601String(),
                properties: is_array($log->properties) ? $log->properties : null,
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusEntries(Asset $asset): array
    {
        return AssetStatusHistory::query()
            ->with('changedBy:id,uuid,first_name,last_name')
            ->where('asset_id', $asset->getKey())
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(function (AssetStatusHistory $row): array {
                $from = $row->from_status;
                $to = $row->to_status;

                return $this->entry(
                    id: 'status-'.$row->getKey(),
                    type: 'status',
                    action: 'status_changed',
                    label: $from instanceof AssetStatus
                        ? "{$from->label()} → {$to->label()}"
                        : "Opened as {$to->label()}",
                    description: $row->reason,
                    actor: $row->changedBy,
                    at: $row->created_at?->toIso8601String(),
                    properties: [
                        'from' => $from?->value,
                        'from_label' => $from?->label(),
                        'to' => $to->value,
                        'to_label' => $to->label(),
                        'tone' => $to->tone(),
                    ],
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transferEntries(Asset $asset): array
    {
        return AssetTransfer::query()
            ->with([
                'fromRoom' => fn ($q) => $q->withTrashed()->with(['floor' => fn ($f) => $f->withTrashed()->with('building')]),
                'toRoom' => fn ($q) => $q->withTrashed()->with(['floor' => fn ($f) => $f->withTrashed()->with('building')]),
                'transferredBy:id,uuid,first_name,last_name',
            ])
            ->where('asset_id', $asset->getKey())
            ->orderByDesc('transferred_at')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(fn (AssetTransfer $row): array => $this->entry(
                id: 'transfer-'.$row->getKey(),
                type: 'transfer',
                action: 'transferred',
                label: sprintf(
                    '%s → %s',
                    $this->roomLabel($row->fromRoom) ?? 'Unassigned',
                    $this->roomLabel($row->toRoom) ?? 'Unassigned',
                ),
                description: $row->reason ?: $row->remarks,
                actor: $row->transferredBy,
                at: $row->transferred_at?->toIso8601String(),
                properties: [
                    'from' => $this->roomLabel($row->fromRoom),
                    'to' => $this->roomLabel($row->toRoom),
                    'remarks' => $row->remarks,
                ],
            ))
            ->all();
    }

    /**
     * Fitted into / removed from a PC. `withTrashed` on the host so an archived
     * PC still explains where this part used to live (FR-PC-004).
     *
     * @return list<array<string, mixed>>
     */
    private function installationEntries(Asset $asset): array
    {
        return PcComponentInstallation::query()
            ->with(['pcUnit' => fn ($q) => $q->withTrashed(), 'installedBy:id,uuid,first_name,last_name'])
            ->where('asset_id', $asset->getKey())
            ->orderByDesc('installation_date')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(function (PcComponentInstallation $row): array {
                // Loaded `withTrashed`, so an archived host still explains where
                // this part used to live — but the relation can still be absent,
                // hence the explicit check rather than a nullsafe chain.
                $host = $row->getRelationValue('pcUnit');
                $name = $host instanceof PcUnit ? $host->pc_name : 'a PC unit';

                return $this->entry(
                    id: 'installation-'.$row->getKey(),
                    type: 'installation',
                    action: $row->removal_date !== null ? 'component_removed' : 'component_installed',
                    label: ($row->removal_date !== null ? 'Removed from ' : 'Installed in ').$name,
                    description: $row->remarks,
                    actor: $row->installedBy,
                    at: ($row->removal_date ?? $row->installation_date)?->toIso8601String(),
                    properties: [
                        'pc_unit' => $host instanceof PcUnit ? $host->uuid : null,
                        'pc_name' => $host instanceof PcUnit ? $host->pc_name : null,
                        'installation_status' => $row->installation_status->value,
                    ],
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maintenanceEntries(Asset $asset): array
    {
        return $this->mapMaintenance(
            MaintenanceRecord::query()
                ->with(['type', 'technician:id,uuid,first_name,last_name'])
                ->where('asset_id', $asset->getKey())
                ->orderByDesc('maintenance_date')
                ->limit(self::PER_SOURCE_CAP)
                ->get()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pcMaintenanceEntries(PcUnit $pcUnit): array
    {
        return $this->mapMaintenance(
            MaintenanceRecord::query()
                ->with(['type', 'technician:id,uuid,first_name,last_name'])
                ->where('pc_unit_id', $pcUnit->getKey())
                ->orderByDesc('maintenance_date')
                ->limit(self::PER_SOURCE_CAP)
                ->get()
        );
    }

    /**
     * @param  Collection<int, MaintenanceRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function mapMaintenance(Collection $records): array
    {
        return $records
            ->map(fn (MaintenanceRecord $row): array => $this->entry(
                id: 'maintenance-'.$row->getKey(),
                type: 'maintenance',
                action: 'maintenance_'.$row->status->value,
                label: $row->title,
                description: $row->resolution ?: $row->diagnosis,
                actor: $row->technician,
                at: ($row->completed_at ?? $row->maintenance_date ?? $row->scheduled_for ?? $row->created_at)?->toIso8601String(),
                properties: [
                    'id' => $row->uuid,
                    'status' => $row->status->value,
                    'type' => $row->type?->name,
                    'downtime_minutes' => $row->downtime_minutes,
                    'cost' => $row->cost,
                ],
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pcInstallationEntries(PcUnit $pcUnit): array
    {
        return PcComponentInstallation::query()
            ->with([
                'asset' => fn ($q) => $q->withTrashed()->with('hardwareModel'),
                'installedBy:id,uuid,first_name,last_name',
            ])
            ->where('pc_unit_id', $pcUnit->getKey())
            ->orderByDesc('installation_date')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(fn (PcComponentInstallation $row): array => $this->entry(
                id: 'installation-'.$row->getKey(),
                type: 'installation',
                action: $row->removal_date !== null ? 'component_removed' : 'component_installed',
                label: ($row->removal_date !== null ? 'Removed ' : 'Installed ')
                    .($row->asset?->displayName() ?? 'a component'),
                description: $row->remarks,
                actor: $row->installedBy,
                at: ($row->removal_date ?? $row->installation_date)?->toIso8601String(),
                properties: [
                    'asset' => $row->asset?->uuid,
                    'asset_tag' => $row->asset?->asset_tag,
                    'installation_status' => $row->installation_status->value,
                ],
            ))
            ->all();
    }

    /**
     * Faults reported against this PC. Tickets carry `pc_unit_id` only, which is
     * why they appear on a PC's timeline and not on a loose asset's.
     *
     * @return list<array<string, mixed>>
     */
    private function ticketEntries(PcUnit $pcUnit): array
    {
        return Ticket::query()
            ->with(['status', 'reporter:id,uuid,first_name,last_name'])
            ->where('pc_unit_id', $pcUnit->getKey())
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(function (Ticket $row): array {
                $status = $row->getRelationValue('status');

                return $this->entry(
                    id: 'ticket-'.$row->getKey(),
                    type: 'ticket',
                    action: 'ticket_reported',
                    label: $row->ticket_number.' · '.$row->title,
                    description: $status?->name,
                    actor: $row->reporter,
                    at: $row->created_at?->toIso8601String(),
                    properties: [
                        'id' => $row->uuid,
                        'number' => $row->ticket_number,
                        'status' => $status?->name,
                        'is_open' => $status !== null && (bool) $status->is_open,
                        'resolved_at' => $row->resolved_at?->toIso8601String(),
                    ],
                );
            })
            ->all();
    }

    /**
     * Label issue and withdrawal. Revoked codes stay in the timeline — that is
     * the whole point of revoking rather than deleting (FR-QR-007).
     *
     * @return list<array<string, mixed>>
     */
    private function qrEntries(Asset|PcUnit $subject): array
    {
        $column = $subject instanceof PcUnit ? 'pc_unit_id' : 'asset_id';

        return QrCode::query()
            ->where($column, $subject->getKey())
            ->orderByDesc('generated_at')
            ->limit(self::PER_SOURCE_CAP)
            ->get()
            ->map(fn (QrCode $row): array => $this->entry(
                id: 'qr-'.$row->getKey(),
                type: 'qr',
                action: 'qr_'.$row->status->value,
                label: 'QR code '.$row->code.' '.($row->status->value === 'active' ? 'issued' : $row->status->value),
                description: $row->location_label,
                actor: null,
                at: ($row->generated_at ?? $row->created_at)?->toIso8601String(),
                properties: [
                    'code' => $row->code,
                    'status' => $row->status->value,
                    'last_scanned_at' => $row->last_scanned_at?->toIso8601String(),
                ],
            ))
            ->all();
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Merge every source, drop entries with no timestamp (they cannot be placed
     * on a timeline), and order newest first.
     *
     * @param  list<array<string, mixed>>  ...$sources
     * @return list<array<string, mixed>>
     */
    private function collect(array ...$sources): array
    {
        $merged = array_merge(...$sources);

        $merged = array_values(array_filter(
            $merged,
            static fn (array $entry): bool => $entry['at'] !== null,
        ));

        usort($merged, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(array $entries, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        return new LengthAwarePaginator(
            array_slice($entries, ($page - 1) * $perPage, $perPage),
            count($entries),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed>
     */
    private function entry(
        string $id,
        string $type,
        string $action,
        string $label,
        ?string $description,
        ?User $actor,
        ?string $at,
        ?array $properties = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'action' => $action,
            'label' => $label,
            'description' => $description,
            'actor' => $actor !== null
                ? ['id' => $actor->uuid, 'name' => $actor->fullName()]
                : null,
            'properties' => $properties,
            'at' => $at,
        ];
    }

    /** Resolve a stored action string to its enum label, tolerating unknowns. */
    private function actionLabel(string $action): string
    {
        return ActivityAction::tryFrom($action)?->label()
            ?? ucfirst(str_replace('_', ' ', $action));
    }

    private function roomLabel(mixed $room): ?string
    {
        if ($room === null) {
            return null;
        }

        $floor = $room->floor;

        $parts = array_values(array_filter([
            $floor?->building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $room->name,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inventory and maintenance aggregates for the role dashboards
 * (SRS FR-DSH-003/004, FR-AST-010, FR-MNT-007).
 *
 * Same discipline as {@see TicketMetrics}: grouped rollups and bounded lists, no
 * PHP-side counting (FR-DSH-005).
 */
class AssetMetrics
{
    /**
     * Serialized asset counts per status, zero-filled across the enum so the
     * distribution is stable, plus the live total.
     *
     * @return array{total: int, by_status: list<array{key: string, label: string, count: int}>}
     */
    public function assets(): array
    {
        $counts = DB::table('assets')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'status');

        $byStatus = array_map(static fn (AssetStatus $status): array => [
            'key' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], AssetStatus::cases());

        return [
            'total' => (int) $counts->sum(),
            'by_status' => $byStatus,
        ];
    }

    /**
     * Consumables at or below their reorder level (FR-AST-010) — the count plus a
     * bounded worst-first list for the dashboard panel.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function lowStock(int $limit = 5): array
    {
        $query = fn () => Consumable::query()
            ->where('is_active', true)
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level');

        $items = $query()
            ->orderBy('quantity_on_hand')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Consumable $consumable): array => [
                // `consumables` carries no uuid; the unique `item_code` is the
                // stable public handle until the Inventory module addresses them.
                'id' => $consumable->item_code,
                'name' => $consumable->name,
                'item_code' => $consumable->item_code,
                'quantity_on_hand' => (int) $consumable->quantity_on_hand,
                'reorder_level' => (int) $consumable->reorder_level,
                'unit_of_measure' => $consumable->unit_of_measure,
            ])
            ->all();

        return [
            'count' => $query()->count(),
            'items' => $items,
        ];
    }

    /**
     * Preventive/corrective maintenance posture (FR-MNT-002/007): overdue,
     * due inside the lead window, and the open workload.
     *
     * @return array{overdue: int, due_soon: int, in_progress: int, scheduled: int, lead_days: int}
     */
    public function maintenancePosture(int $leadDays = 7, ?User $technician = null): array
    {
        $now = now();
        $threshold = $now->copy()->addDays($leadDays);

        $base = function () use ($technician) {
            $query = MaintenanceRecord::query();

            if ($technician !== null) {
                $query->where('technician_id', $technician->getKey());
            }

            return $query;
        };

        $openStatuses = [
            MaintenanceStatus::Scheduled->value,
            MaintenanceStatus::InProgress->value,
            MaintenanceStatus::OnHold->value,
        ];

        return [
            'overdue' => $base()
                ->whereIn('status', $openStatuses)
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<', $now)
                ->count(),
            'due_soon' => $base()
                ->whereIn('status', $openStatuses)
                ->whereNotNull('scheduled_for')
                ->whereBetween('scheduled_for', [$now, $threshold])
                ->count(),
            'in_progress' => $base()->where('status', MaintenanceStatus::InProgress->value)->count(),
            'scheduled' => $base()->where('status', MaintenanceStatus::Scheduled->value)->count(),
            'lead_days' => $leadDays,
        ];
    }

    /**
     * Upcoming/overdue maintenance as a bounded, soonest-first list.
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingMaintenance(int $limit = 5, ?User $technician = null): array
    {
        $query = MaintenanceRecord::query()
            ->with(['pcUnit', 'type'])
            ->whereIn('status', [
                MaintenanceStatus::Scheduled->value,
                MaintenanceStatus::InProgress->value,
                MaintenanceStatus::OnHold->value,
            ])
            ->whereNotNull('scheduled_for');

        if ($technician !== null) {
            $query->where('technician_id', $technician->getKey());
        }

        return $query
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get()
            ->map(fn (MaintenanceRecord $record): array => [
                'id' => $record->uuid,
                'title' => $record->title,
                'type' => $record->type?->name,
                'status' => $record->status->value,
                // Null when the record targets a standalone asset rather than a PC.
                'target' => $record->pcUnit?->pc_name,
                'scheduled_for' => $record->scheduled_for?->toIso8601String(),
                'overdue' => $record->scheduled_for !== null && $record->scheduled_for->isPast(),
            ])
            ->all();
    }

    /**
     * PC-unit condition mix — how much of the estate is healthy.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function pcUnitStatus(): array
    {
        /** @var Collection<string, int> $counts */
        $counts = DB::table('pc_units')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'status');

        return array_map(static fn (PcStatus $status): array => [
            'key' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], PcStatus::cases());
    }

    /* ------------------------------------------------------------------ *
     | Phase 2.5 — Asset Management dashboard (SRS FR-AST-011, FR-DSH-003) |
     |                                                                     |
     | Same discipline as above: grouped rollups and bounded lists, always  |
     | as database aggregates — no PHP-side counting (FR-DSH-005). Every    |
     | figure here is also a *link target*: the client turns each tile into |
     | a pre-filtered directory query, so the numbers are navigable rather  |
     | than decorative.                                                     |
     * ------------------------------------------------------------------ */

    /**
     * Headline counts for the asset dashboard, in the Client's operational
     * vocabulary. One grouped query answers all of them.
     *
     * @return array<string, int>
     */
    public function assetSummary(): array
    {
        $counts = DB::table('assets')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'status');

        $at = static fn (AssetStatus $status): int => (int) ($counts[$status->value] ?? 0);

        return [
            'total' => (int) $counts->sum(),
            'new' => $at(AssetStatus::New),
            'available' => $at(AssetStatus::InStock),
            'assigned' => $at(AssetStatus::Reserved),
            'in_service' => $at(AssetStatus::Deployed),
            'maintenance' => $at(AssetStatus::InRepair),
            'out_of_service' => $at(AssetStatus::OutOfService),
            'in_transit' => $at(AssetStatus::InTransit),
            'retired' => $at(AssetStatus::Retired),
            'disposed' => $at(AssetStatus::Disposed),
            'archived' => (int) DB::table('assets')->whereNotNull('deleted_at')->count(),
            'unassigned_location' => (int) DB::table('assets')
                ->whereNull('deleted_at')
                ->whereNull('current_room_id')
                ->count(),
        ];
    }

    /**
     * Live assets per building, busiest first. Assets with no room are reported
     * under an explicit "Unassigned" row rather than silently dropped — an
     * unplaced asset is exactly the thing an administrator needs to notice.
     *
     * @return list<array{key: string|null, label: string, count: int}>
     */
    public function byBuilding(int $limit = 10): array
    {
        $rows = DB::table('assets')
            ->leftJoin('rooms', 'rooms.id', '=', 'assets.current_room_id')
            ->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->whereNull('assets.deleted_at')
            ->groupBy('buildings.uuid', 'buildings.name')
            ->select('buildings.uuid as uuid', 'buildings.name as name', DB::raw('count(*) as aggregate'))
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get();

        return $rows->map(static fn (object $row): array => [
            'key' => $row->uuid,
            'label' => $row->name ?? 'Unassigned',
            'count' => (int) $row->aggregate,
        ])->all();
    }

    /**
     * Live assets per room, busiest first. Rooms holding nothing are absent by
     * construction — the question is "where is the equipment", not "list rooms".
     *
     * @return list<array{key: string|null, label: string, count: int}>
     */
    public function byRoom(int $limit = 10): array
    {
        $rows = DB::table('assets')
            ->join('rooms', 'rooms.id', '=', 'assets.current_room_id')
            ->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->whereNull('assets.deleted_at')
            ->groupBy('rooms.uuid', 'rooms.name', 'buildings.name')
            ->select(
                'rooms.uuid as uuid',
                'rooms.name as room_name',
                'buildings.name as building_name',
                DB::raw('count(*) as aggregate'),
            )
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get();

        return $rows->map(static fn (object $row): array => [
            'key' => $row->uuid,
            'label' => $row->building_name !== null
                ? $row->building_name.' · '.$row->room_name
                : (string) $row->room_name,
            'count' => (int) $row->aggregate,
        ])->all();
    }

    /**
     * Live assets per catalog category, zero-filled across the enum so the
     * distribution is stable between refreshes.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function byCategory(): array
    {
        $counts = DB::table('assets')
            ->join('hardware_models', 'hardware_models.id', '=', 'assets.hardware_model_id')
            ->join('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
            ->whereNull('assets.deleted_at')
            ->groupBy('hardware_components.component_type')
            ->select('hardware_components.component_type as component_type', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'component_type');

        $rows = array_map(static fn (ComponentType $type): array => [
            'key' => $type->value,
            'label' => $type->label(),
            'count' => (int) ($counts[$type->value] ?? 0),
        ], ComponentType::cases());

        // Categories nothing is filed under would be a wall of zeros; keep the
        // ones in use, ordered by size.
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['count'] > 0));

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * Warranties lapsing inside the window, soonest first (FR-AST-002). Already
     * lapsed warranties are excluded — this list is for acting in time.
     *
     * @return array{count: int, days: int, items: list<array<string, mixed>>}
     */
    public function warrantyExpiring(int $days = 90, int $limit = 5): array
    {
        $query = fn () => Asset::query()
            ->whereNotNull('warranty_expiration')
            ->whereBetween('warranty_expiration', [now()->toDateString(), now()->addDays($days)->toDateString()]);

        $items = $query()
            ->with(['hardwareModel', 'currentRoom'])
            ->orderBy('warranty_expiration')
            ->limit($limit)
            ->get()
            ->map(static fn (Asset $asset): array => [
                'id' => $asset->uuid,
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->displayName(),
                'room' => $asset->currentRoom?->name,
                'warranty_expiration' => $asset->warranty_expiration?->toIso8601String(),
                'days_remaining' => $asset->warrantyDaysRemaining(),
            ])
            ->all();

        return ['count' => $query()->count(), 'days' => $days, 'items' => $items];
    }

    /**
     * Most recently created assets (FR-AST-011).
     *
     * @return list<array<string, mixed>>
     */
    public function recentlyAdded(int $limit = 5): array
    {
        return $this->assetList(Asset::query()->latest('created_at')->orderByDesc('id'), $limit, 'created_at');
    }

    /**
     * Most recently touched assets — the technician's "what changed while I was
     * out" list.
     *
     * @return list<array<string, mixed>>
     */
    public function recentlyUpdated(int $limit = 5): array
    {
        return $this->assetList(Asset::query()->latest('updated_at')->orderByDesc('id'), $limit, 'updated_at');
    }

    /**
     * Assets in this technician's custody. Scoped strictly to the caller: a
     * technician's dashboard shows their own charges, never the estate
     * (SDD DD-38).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function assignedTo(User $technician, int $limit = 5): array
    {
        $query = fn () => Asset::query()->where('assigned_technician_id', $technician->getKey());

        return [
            'count' => $query()->count(),
            'items' => $this->assetList($query()->latest('updated_at'), $limit, 'updated_at'),
        ];
    }

    /**
     * Assets currently in repair or out of service — optionally narrowed to one
     * technician's custody.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function underMaintenance(?User $technician = null, int $limit = 5): array
    {
        $query = function () use ($technician) {
            $builder = Asset::query()->whereIn('status', [
                AssetStatus::InRepair->value,
                AssetStatus::OutOfService->value,
            ]);

            if ($technician !== null) {
                $builder->where('assigned_technician_id', $technician->getKey());
            }

            return $builder;
        };

        return [
            'count' => $query()->count(),
            'items' => $this->assetList($query()->latest('updated_at'), $limit, 'updated_at'),
        ];
    }

    /**
     * Shared row shape for the dashboard's bounded asset lists.
     *
     * @param  Builder<Asset>  $query
     * @return list<array<string, mixed>>
     */
    private function assetList(Builder $query, int $limit, string $timestamp): array
    {
        return $query
            ->with(['hardwareModel', 'currentRoom'])
            ->limit($limit)
            ->get()
            ->map(static fn (Asset $asset): array => [
                'id' => $asset->uuid,
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->displayName(),
                'status' => $asset->status->value,
                'status_label' => $asset->status->label(),
                'tone' => $asset->status->tone(),
                'room' => $asset->currentRoom?->name,
                'at' => $asset->{$timestamp}?->toIso8601String(),
            ])
            ->all();
    }
}

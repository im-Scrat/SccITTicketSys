<?php

declare(strict_types=1);

namespace App\Domains\Locations\Services;

use App\Domains\Identity\Services\UserMetrics;
use App\Enums\ActivityAction;
use App\Enums\RoomType;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Room;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the Location Management dashboard (SRS FR-LOC) in a small, fixed
 * set of database aggregate queries — counts are never materialised into PHP
 * collections. Feeds LocationDashboardController and LocationTreeController;
 * mirrors {@see UserMetrics}.
 *
 * The summary/occupancy aggregates go through the query builder (not Eloquent)
 * because they are pure `count(*) filter (...)` rollups: one round trip per
 * table, explicit `deleted_at` predicates, and no model hydration.
 */
class LocationMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'summary' => $this->summary(),
            'by_room_type' => $this->byRoomType(),
            'occupancy' => $this->occupancy(),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /**
     * Live / active / inactive / archived totals per level, plus seated capacity.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $buildings = DB::table('buildings')
            ->selectRaw('count(*) filter (where deleted_at is null) as live')
            ->selectRaw('count(*) filter (where deleted_at is null and is_active) as active')
            ->selectRaw('count(*) filter (where deleted_at is null and not is_active) as inactive')
            ->selectRaw('count(*) filter (where deleted_at is not null) as archived')
            ->first();

        $floors = DB::table('floors')
            ->selectRaw('count(*) filter (where deleted_at is null) as live')
            ->selectRaw('count(*) filter (where deleted_at is not null) as archived')
            ->first();

        $rooms = DB::table('rooms')
            ->selectRaw('count(*) filter (where deleted_at is null) as live')
            ->selectRaw('count(*) filter (where deleted_at is null and is_active) as active')
            ->selectRaw('count(*) filter (where deleted_at is null and not is_active) as inactive')
            ->selectRaw('count(*) filter (where deleted_at is not null) as archived')
            ->selectRaw('coalesce(sum(capacity) filter (where deleted_at is null), 0) as capacity')
            ->first();

        return [
            'buildings' => (int) ($buildings->live ?? 0),
            'buildings_active' => (int) ($buildings->active ?? 0),
            'buildings_inactive' => (int) ($buildings->inactive ?? 0),
            'buildings_archived' => (int) ($buildings->archived ?? 0),
            'floors' => (int) ($floors->live ?? 0),
            'floors_archived' => (int) ($floors->archived ?? 0),
            'rooms' => (int) ($rooms->live ?? 0),
            'rooms_active' => (int) ($rooms->active ?? 0),
            'rooms_inactive' => (int) ($rooms->inactive ?? 0),
            'rooms_archived' => (int) ($rooms->archived ?? 0),
            'total_capacity' => (int) ($rooms->capacity ?? 0),
        ];
    }

    /**
     * Live room counts per `room_type`, always returning every type (zeroed when
     * absent) so the dashboard renders a stable set of tiles.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function byRoomType(): array
    {
        /** @var Collection<string, int> $counts */
        $counts = Room::query()
            ->selectRaw('room_type, count(*) as aggregate')
            ->groupBy('room_type')
            ->pluck('aggregate', 'room_type');

        return array_map(static fn (RoomType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'count' => (int) ($counts[$type->value] ?? 0),
        ], RoomType::cases());
    }

    /**
     * How completely the estate is mapped: PC units placed in a room versus
     * unplaced, empty rooms, and buildings that have no rooms yet.
     *
     * @return array<string, int>
     */
    public function occupancy(): array
    {
        $placement = DB::table('pc_units')
            ->whereNull('deleted_at')
            ->selectRaw('count(*) filter (where room_id is not null) as placed')
            ->selectRaw('count(*) filter (where room_id is null) as unplaced')
            ->first();

        return [
            'pc_units_placed' => (int) ($placement->placed ?? 0),
            'pc_units_unplaced' => (int) ($placement->unplaced ?? 0),
            'rooms_with_pc_units' => Room::query()->whereHas('pcUnits')->count(),
            'empty_rooms' => Room::query()->whereDoesntHave('pcUnits')->count(),
            'buildings_without_rooms' => Building::query()->whereDoesntHave('rooms')->count(),
        ];
    }

    /**
     * Most recent location administration events from the activity log.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $limit = 8): array
    {
        return ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->where('module', 'locations')
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'actor' => $log->user !== null ? [
                    'id' => $log->user->uuid,
                    'name' => $log->user->fullName(),
                ] : null,
                'action' => $log->action,
                'label' => ActivityAction::tryFrom($log->action)?->label() ?? $log->action,
                'description' => $log->description,
                'at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The location tree: every building with its floors and per-level counts.
     * Bounded by design — rooms are fetched per floor on expand
     * (`GET /admin/rooms?floor=<uuid>`), so a large estate is never one payload.
     *
     * @return EloquentCollection<int, Building>
     */
    public function tree(): EloquentCollection
    {
        return Building::query()
            ->withCount(['floors', 'rooms'])
            ->with(['floors' => function (Relation $query): void {
                $query->withCount('rooms')->orderBy('floor_number');
            }])
            ->orderBy('name')
            ->get();
    }
}

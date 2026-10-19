<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use App\Enums\PcStatus;
use App\Models\FloorPlanFixture;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The Floorplan room overview (`GET /admin/floor-plan/rooms`, Phase 2.11).
 *
 * One card per live room: its place in the estate, how many machines it holds
 * and in which repair status, whether a Floorplan exists, how much of it is
 * placed, who last saved it, and a small **preview** of the layout for the
 * card's thumbnail.
 *
 * Built in a fixed number of queries for the page — rooms (with status counts
 * as sub-selects), their active layouts, the trusted positions on those
 * layouts, and their fixtures — never one query per card. The preview is
 * integer-rounded and capped at {@see PREVIEW_LIMIT} objects per room: it is a
 * thumbnail, not the plan, and the cap keeps an unusually busy room from
 * bloating the whole page.
 *
 * Positions are trusted exactly as `FloorPlanService` trusts them — only while
 * the machine is still live in the room — so a card never counts, or draws, a
 * machine that has left.
 */
final class FloorPlanOverviewQuery
{
    public const PREVIEW_LIMIT = 300;

    /**
     * @param  array<string, mixed>  $params
     * @return array{rooms: LengthAwarePaginator<int, Room>, layouts: array<int, array<string, mixed>>, unassigned: int}
     */
    public function handle(User $actor, array $params): array
    {
        Gate::forUser($actor)->authorize('viewAny', RoomLayout::class);

        $query = Room::query()
            ->with(['floor.building'])
            ->withCount([
                'pcUnits',
                'pcUnits as pending_repair_count' => fn (Builder $q) => $q->where('status', PcStatus::PendingRepair->value),
                'pcUnits as needs_fix_count' => fn (Builder $q) => $q->where('status', PcStatus::NeedsFix->value),
            ]);

        $this->filter($query, $params);
        $this->sort($query, (string) ($params['sort'] ?? 'name'));

        /** @var LengthAwarePaginator<int, Room> $rooms */
        $rooms = $query->paginate((int) ($params['per_page'] ?? 24))->withQueryString();

        return [
            'rooms' => $rooms,
            'layouts' => $this->layoutsFor(array_map(static fn (Room $room): int => (int) $room->getKey(), $rooms->items())),
            'unassigned' => DB::table('pc_units')->whereNull('deleted_at')->whereNull('room_id')->count(),
        ];
    }

    /**
     * @param  Builder<Room>  $query
     * @param  array<string, mixed>  $params
     */
    private function filter(Builder $query, array $params): void
    {
        $term = trim((string) ($params['search'] ?? ''));

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
            $query->where(fn (Builder $q) => $q->where('rooms.name', 'ilike', $like)
                ->orWhere('rooms.code', 'ilike', $like)
                ->orWhere('rooms.room_number', 'ilike', $like));
        }

        if (isset($params['building'])) {
            $query->whereHas('floor.building', fn (Builder $q) => $q->where('uuid', (string) $params['building']));
        }

        if (isset($params['room_type'])) {
            $query->where('rooms.room_type', (string) $params['room_type']);
        }

        $layout = $params['layout'] ?? null;

        if ($layout === 'set_up') {
            $query->whereHas('layouts', fn (Builder $q) => $q->where('is_active', true));
        } elseif ($layout === 'not_set_up') {
            $query->whereDoesntHave('layouts', fn (Builder $q) => $q->where('is_active', true));
        }
    }

    /**
     * @param  Builder<Room>  $query
     */
    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'pcs' => $query->orderByDesc('pc_units_count'),
            'attention' => $query->orderByDesc('needs_fix_count')->orderByDesc('pending_repair_count'),
            default => null,
        };

        $query->orderBy('rooms.name')->orderBy('rooms.id');
    }

    /**
     * Active layout summary and preview per room id.
     *
     * @param  list<int>  $roomIds
     * @return array<int, array<string, mixed>>
     */
    private function layoutsFor(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $layouts = RoomLayout::query()
            ->whereIn('room_id', $roomIds)
            ->where('is_active', true)
            ->with('updatedBy:id,first_name,middle_name,last_name')
            ->get();

        if ($layouts->isEmpty()) {
            return [];
        }

        $positions = DB::table('floor_plan_positions as p')
            ->join('room_layouts as l', 'l.id', '=', 'p.room_layout_id')
            ->join('pc_units as u', function ($join) {
                $join->on('u.id', '=', 'p.pc_unit_id')->on('u.room_id', '=', 'l.room_id');
            })
            ->whereNull('u.deleted_at')
            ->whereIn('p.room_layout_id', $layouts->modelKeys())
            ->orderBy('p.id')
            ->get(['p.room_layout_id', 'p.pos_x', 'p.pos_y', 'p.rotation', 'u.status'])
            ->groupBy('room_layout_id');

        $fixtures = FloorPlanFixture::query()
            ->whereIn('room_layout_id', $layouts->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('room_layout_id');

        $out = [];

        foreach ($layouts as $layout) {
            $pcs = $positions->get($layout->getKey(), collect());
            $shapes = $fixtures->get($layout->getKey(), collect());
            $total = $pcs->count() + $shapes->count();
            $fixtureRoom = max(0, self::PREVIEW_LIMIT - $pcs->count());

            $out[$layout->room_id] = [
                'layout' => $layout,
                'placed' => $pcs->count(),
                'preview' => [
                    'pcs' => $pcs->take(self::PREVIEW_LIMIT)
                        ->map(fn (object $p): array => [
                            (int) round((float) $p->pos_x),
                            (int) round((float) $p->pos_y),
                            (int) round((float) $p->rotation),
                            PcStatus::fromStored((string) $p->status)->value,
                        ])->values()->all(),
                    'fixtures' => $shapes->take($fixtureRoom)
                        ->map(fn (FloorPlanFixture $f): array => [
                            $f->kind->value,
                            (int) round((float) $f->pos_x),
                            (int) round((float) $f->pos_y),
                            (int) round((float) $f->width),
                            (int) round((float) $f->height),
                            (int) round((float) $f->rotation),
                        ])->values()->all(),
                    'truncated' => $total > self::PREVIEW_LIMIT,
                ],
            ];
        }

        return $out;
    }
}

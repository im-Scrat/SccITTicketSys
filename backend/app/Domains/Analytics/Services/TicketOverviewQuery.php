<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportPeriod;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Enums\ReportGranularity;
use App\Enums\RoomType;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The aggregate SQL behind the ticket overview (Phase 2.10, SDD DD-88).
 *
 * Every figure starts from `TicketDirectoryQuery::reportScope()` — the same
 * population the ticket directory lists — converted to a plain query, so the
 * eager loads a list needs never run for a count. Each method clones the scope
 * it is given; nothing here mutates a caller's builder.
 *
 * Time buckets are computed in SQL as school-local dates —
 * `date_trunc(unit, created_at AT TIME ZONE 'Asia/Manila')` — with the unit and
 * zone bound as parameters and `GROUP BY 1` (repeating a bound expression in
 * GROUP BY would be a second, different parameter). PHP builds the bucket list
 * from `ReportPeriod::buckets()` and merges counts onto it by key; a test proves
 * the two agree at the edge instants.
 */
final class TicketOverviewQuery
{
    /** Room types counted as "other" in the labs-and-offices comparison. */
    public const OTHER_ROOM_TYPES = [
        RoomType::Storage,
        RoomType::ServerRoom,
        RoomType::FacultyRoom,
        RoomType::Library,
        RoomType::Other,
    ];

    public function __construct(private readonly TicketDirectoryQuery $directory) {}

    /**
     * @param  array<string, string>  $params  ticket-directory parameters
     */
    public function scope(array $params, User $actor, ?CarbonImmutable $reportedBefore = null): Builder
    {
        return $this->directory->reportScope($params, $actor, $reportedBefore)->toBase();
    }

    /**
     * Cohort totals in one statement.
     *
     * @return array{total: int, duplicates: int, cancelled: int, open: int, awaiting_confirmation: int, without_history: int}
     */
    public function totals(Builder $scope): array
    {
        $row = (clone $scope)
            ->join('ticket_statuses as overview_status', 'overview_status.id', '=', 'tickets.current_status_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where tickets.duplicate_of_id is not null) as duplicates')
            ->selectRaw("count(*) filter (where overview_status.slug = 'cancelled') as cancelled")
            ->selectRaw('count(*) filter (where overview_status.is_open) as open')
            ->selectRaw("count(*) filter (where overview_status.slug = 'resolved') as awaiting_confirmation")
            ->selectRaw('count(*) filter (where not exists (select 1 from ticket_status_history h where h.ticket_id = tickets.id)) as without_history')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'duplicates' => (int) ($row->duplicates ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'open' => (int) ($row->open ?? 0),
            'awaiting_confirmation' => (int) ($row->awaiting_confirmation ?? 0),
            'without_history' => (int) ($row->without_history ?? 0),
        ];
    }

    /**
     * Tickets per current status id.
     *
     * @return array<int, int>
     */
    public function statusCounts(Builder $scope): array
    {
        return (clone $scope)
            ->selectRaw('tickets.current_status_id as status_id, count(*) as n')
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->status_id => (int) $row->n])
            ->all();
    }

    /**
     * Tickets per school-local bucket key (`Y-m-d` of the bucket's start).
     *
     * @return array<string, int>
     */
    public function bucketCounts(Builder $scope, ReportGranularity $unit): array
    {
        return (clone $scope)
            ->selectRaw(
                "to_char(date_trunc(?, tickets.created_at AT TIME ZONE ?), 'YYYY-MM-DD') as bucket, count(*) as n",
                [$unit->value, SchoolClock::TIMEZONE],
            )
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->bucket => (int) $row->n])
            ->all();
    }

    /**
     * Problems per room the tickets were reported in, archived and inactive
     * rooms included, busiest first. Tickets with no room are not a place and
     * are counted by {@see typeCounts()} instead.
     *
     * @return list<object>
     */
    public function roomRows(Builder $scope, int $limit): array
    {
        return (clone $scope)
            ->join('ticket_statuses as overview_status', 'overview_status.id', '=', 'tickets.current_status_id')
            ->join('rooms as r', 'r.id', '=', 'tickets.room_id')
            ->leftJoin('floors as f', 'f.id', '=', 'r.floor_id')
            ->leftJoin('buildings as b', 'b.id', '=', 'f.building_id')
            ->groupBy('r.id', 'f.id', 'b.id')
            ->select([
                'r.uuid as room_uuid', 'r.name as room_name', 'r.code as room_code', 'r.room_type',
                'r.is_active as room_active', 'r.deleted_at as room_deleted_at',
                'f.name as floor_name', 'f.floor_number',
                'b.uuid as building_uuid', 'b.name as building_name', 'b.is_active as building_active', 'b.deleted_at as building_deleted_at',
            ])
            ->selectRaw('count(*) as n, count(*) filter (where overview_status.is_open) as open_n')
            ->orderByDesc('n')
            ->orderByDesc('open_n')
            ->orderByRaw('b.name asc nulls last')
            ->orderByRaw('r.name asc nulls last')
            ->orderBy('r.id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Tickets and distinct rooms per room-type group: laboratory, office, other,
     * and `unspecified` for tickets with no room recorded.
     *
     * @return array<string, array{count: int, rooms: int}>
     */
    public function typeCounts(Builder $scope): array
    {
        return (clone $scope)
            ->leftJoin('rooms as r', 'r.id', '=', 'tickets.room_id')
            ->selectRaw(
                "case when tickets.room_id is null then 'unspecified'
                      when r.room_type = 'laboratory' then 'laboratory'
                      when r.room_type = 'office' then 'office'
                      else 'other' end as type_group,
                 count(*) as n,
                 count(distinct tickets.room_id) as rooms",
            )
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->type_group => ['count' => (int) $row->n, 'rooms' => (int) $row->rooms]])
            ->all();
    }

    /**
     * Rooms of each type group that existed at any time during the range —
     * created before it ended and not archived before it began — optionally
     * within one building. The denominator of "problems per room".
     *
     * @return array<string, int>
     */
    public function roomsExisting(ReportPeriod $range, ?string $buildingUuid): array
    {
        return DB::table('rooms as r')
            ->when($buildingUuid !== null, fn (Builder $q) => $q
                ->join('floors as f', 'f.id', '=', 'r.floor_id')
                ->join('buildings as b', 'b.id', '=', 'f.building_id')
                ->where('b.uuid', $buildingUuid))
            ->where('r.created_at', '<', $range->endUtc())
            ->where(fn (Builder $q) => $q->whereNull('r.deleted_at')->orWhere('r.deleted_at', '>=', $range->startUtc()))
            ->selectRaw("case r.room_type when 'laboratory' then 'laboratory' when 'office' then 'office' else 'other' end as type_group, count(*) as n")
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->type_group => (int) $row->n])
            ->all();
    }

    /**
     * When ticket records begin: the first ticket ever filed, archived ones
     * included — the best available marker of go-live.
     */
    public function ticketsStartAt(): ?CarbonImmutable
    {
        $first = DB::table('tickets')->min('created_at');

        return is_string($first) ? CarbonImmutable::parse($first)->setTimezone(SchoolClock::TIMEZONE) : null;
    }
}

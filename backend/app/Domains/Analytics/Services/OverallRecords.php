<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Maintenance\Services\ReplacementAttribution;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\MaintenanceClass;
use App\Enums\PcStatus;
use App\Enums\ProblemArea;
use App\Enums\ReportRecordsTable;
use App\Enums\RoomType;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The record tables behind Overall Reports (Phase 2.10 R4, SDD DD-94):
 * server-paginated, sortable from an allow-list (each with an id tiebreak, so
 * pages never overlap), over **the same populations the section figures count**
 * — a records total always equals its figure.
 *
 * No person is named in any table: no reporter, technician or custodian
 * column. Ticket titles appear (this is the Administrator's own screen); they
 * never reach an emailed document, which uses the identifier-only profile.
 */
final class OverallRecords
{
    public const PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** @var array<string, array<string, string>> table => sort key => SQL column */
    public const SORTS = [
        'tickets' => ['reported' => 'tickets.created_at', 'ticket' => 'tickets.ticket_number'],
        'repairs' => ['completed' => 'm.completed_at', 'type' => 'mt.name'],
        'replacements' => ['replaced' => 'replaced_at_sort'],
        'pc_units' => ['code' => 'p.unit_code', 'registered' => 'p.created_at', 'warranty' => 'p.warranty_expiration'],
        'assets' => ['code' => 'a.asset_tag', 'registered' => 'a.created_at', 'warranty' => 'a.warranty_expiration'],
        'rooms' => ['problems' => 'problems', 'code' => 'r.code'],
    ];

    public function __construct(
        private readonly TicketDirectoryQuery $directory,
        private readonly MaintenanceReportQuery $maintenance,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function page(ReportRecordsTable $table, OverallReportFilters $filters, User $actor, int $page, int $perPage, ?string $sort, string $direction): array
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $sorts = self::SORTS[$table->value];
        $sort = $sort !== null && isset($sorts[$sort]) ? $sort : array_key_first($sorts);
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        [$query, $columns, $map, $idColumn] = match ($table) {
            ReportRecordsTable::Tickets => $this->tickets($filters, $actor),
            ReportRecordsTable::Repairs => $this->repairs($filters),
            ReportRecordsTable::Replacements => $this->replacements($filters),
            ReportRecordsTable::PcUnits => $this->pcUnits($filters),
            ReportRecordsTable::Assets => $this->assets($filters),
            ReportRecordsTable::Rooms => $this->rooms($filters, $actor),
        };

        $total = DB::query()->fromSub(clone $query, 'c')->count();
        $rows = $query
            ->orderBy($sorts[$sort], $direction)
            ->orderBy($idColumn, $direction)
            ->forPage($page, $perPage)
            ->get();

        $mapped = $rows->map($map)->values()->all();

        return [
            'data' => [
                'table' => $table->value,
                'title' => $table->label(),
                'columns' => $columns,
                'rows' => array_column($mapped, 'row'),
                'links' => array_column($mapped, 'link'),
            ],
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'sort' => $sort,
                'direction' => $direction,
                'sorts' => array_keys($sorts),
            ],
        ];
    }

    private function local(?string $instant, string $format = 'Y-m-d H:i'): string
    {
        return $instant !== null ? CarbonImmutable::parse($instant)->setTimezone(SchoolClock::TIMEZONE)->format($format) : '';
    }

    /**
     * @return array{0: Builder, 1: list<array{key: string, label: string, type: string}>, 2: callable, 3: string}
     */
    private function tickets(OverallReportFilters $filters, User $actor): array
    {
        $report = $filters->report->withExtraScope($filters->equipmentParams());
        $query = $this->directory->reportScope($report->directoryParams(), $actor)->toBase()
            ->leftJoin('ticket_categories as c', 'c.id', '=', 'tickets.category_id')
            ->leftJoin('ticket_priorities as pr', 'pr.id', '=', 'tickets.priority_id')
            ->leftJoin('ticket_statuses as s', 's.id', '=', 'tickets.current_status_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'tickets.room_id')
            ->select(['tickets.id', 'tickets.uuid', 'tickets.ticket_number', 'tickets.title', 'tickets.created_at', 'tickets.duplicate_of_id', 'c.name as category', 'c.problem_area', 'pr.name as priority', 's.name as status', 'r.code as room']);

        return [$query, $this->columns([['ticket', 'Ticket', 'code'], ['title', 'Title'], ['category', 'Category'], ['area', 'Area'], ['priority', 'Priority'], ['status', 'Status'], ['room', 'Room', 'code'], ['reported', 'Reported']]),
            fn (object $r): array => [
                'row' => [(string) $r->ticket_number, (string) $r->title.($r->duplicate_of_id !== null ? ' (duplicate)' : ''), (string) ($r->category ?? ''), ProblemArea::tryFrom((string) $r->problem_area)?->label() ?? '', (string) ($r->priority ?? ''), (string) ($r->status ?? ''), (string) ($r->room ?? '—'), $this->local($r->created_at)],
                'link' => ['path' => '/app/tickets/manage/'.$r->uuid, 'query' => []],
            ], 'tickets.id'];
    }

    /**
     * @return array{0: Builder, 1: list<array{key: string, label: string, type: string}>, 2: callable, 3: string}
     */
    private function repairs(OverallReportFilters $filters): array
    {
        $query = $this->maintenance->repairs($filters)
            ->leftJoin('rooms as r', 'r.id', '=', DB::raw('COALESCE(p.room_id, a.current_room_id)'))
            ->select(['m.id', 'm.uuid', 'm.completed_at', 'm.downtime_minutes', 'm.labor_hours', 'mt.name as type', DB::raw(MaintenanceClass::sqlCase().' as class'), DB::raw('COALESCE(p.unit_code, a.asset_tag) as target'), 'r.code as room']);

        return [$query, $this->columns([['completed', 'Completed'], ['type', 'Type'], ['class', 'Class'], ['target', 'Equipment', 'code'], ['room', 'Room now', 'code'], ['downtime', 'Downtime (h)', 'number'], ['labour', 'Labour (h)', 'number']]),
            fn (object $r): array => [
                'row' => [$this->local($r->completed_at), (string) $r->type, MaintenanceClass::from((string) $r->class)->label(), (string) ($r->target ?? '—'), (string) ($r->room ?? '—'), $r->downtime_minutes !== null ? number_format((int) $r->downtime_minutes / 60, 1) : '—', $r->labor_hours !== null ? number_format((float) $r->labor_hours, 1) : '—'],
                'link' => ['path' => '/app/maintenance/'.$r->uuid, 'query' => []],
            ], 'm.id'];
    }

    /**
     * @return array{0: Builder, 1: list<array{key: string, label: string, type: string}>, 2: callable, 3: string}
     */
    private function replacements(OverallReportFilters $filters): array
    {
        $query = $this->maintenance->replacements($filters)
            ->select(['hr.id', 'm.uuid as record_uuid', 'hr.quantity', DB::raw('COALESCE(hr.replaced_at, hr.created_at) as replaced_at_sort'), DB::raw(ReplacementAttribution::typeSql().' as type'), DB::raw(ReplacementAttribution::basisSql().' as basis'), 'hcn.name as fitted', DB::raw('COALESCE(p.unit_code, a.asset_tag) as target')]);

        return [$query, $this->columns([['replaced', 'Replaced'], ['type', 'Failed part'], ['basis', 'Identified by'], ['fitted', 'Fitted'], ['quantity', 'Qty', 'number'], ['target', 'Equipment', 'code']]),
            fn (object $r): array => [
                'row' => [$this->local($r->replaced_at_sort), $r->type !== null ? (ComponentType::tryFrom((string) $r->type)?->label() ?? (string) $r->type) : 'Not recorded', ReplacementAttribution::LABELS[(string) $r->basis] ?? '', (string) ($r->fitted ?? '—'), (int) $r->quantity, (string) ($r->target ?? '—')],
                'link' => ['path' => '/app/maintenance/'.$r->record_uuid, 'query' => []],
            ], 'hr.id'];
    }

    /**
     * @return array{0: Builder, 1: list<array{key: string, label: string, type: string}>, 2: callable, 3: string}
     */
    private function pcUnits(OverallReportFilters $filters): array
    {
        $query = ReportScopes::location(DB::table('pc_units as p')->whereNull('p.deleted_at'), 'p.room_id', $filters->report)
            ->leftJoin('rooms as r', 'r.id', '=', 'p.room_id')
            ->select(['p.id', 'p.uuid', 'p.unit_code', 'p.pc_name', 'p.status', 'p.current_condition', 'p.warranty_expiration', 'p.created_at', 'r.code as room']);
        if ($filters->equipmentType === 'pc') {
            $query->where('p.uuid', $filters->equipmentUuid);
        }

        return [$query, $this->columns([['code', 'Code', 'code'], ['name', 'Name'], ['room', 'Room', 'code'], ['status', 'Status'], ['condition', 'Condition'], ['warranty', 'Warranty until'], ['registered', 'Registered']]),
            fn (object $r): array => [
                'row' => [(string) $r->unit_code, (string) $r->pc_name, (string) ($r->room ?? '—'), PcStatus::tryFrom((string) $r->status)?->label() ?? (string) $r->status, ucfirst(str_replace('_', ' ', (string) $r->current_condition)), $r->warranty_expiration !== null ? substr((string) $r->warranty_expiration, 0, 10) : '—', $this->local($r->created_at, 'Y-m-d')],
                'link' => ['path' => '/app/assets/pc-units/'.$r->uuid, 'query' => []],
            ], 'p.id'];
    }

    /**
     * @return array{0: Builder, 1: list<array{key: string, label: string, type: string}>, 2: callable, 3: string}
     */
    private function assets(OverallReportFilters $filters): array
    {
        $query = ReportScopes::location(DB::table('assets as a')->whereNull('a.deleted_at'), 'a.current_room_id', $filters->report)
            ->leftJoin('rooms as r', 'r.id', '=', 'a.current_room_id')
            ->leftJoin('hardware_models as hm', 'hm.id', '=', 'a.hardware_model_id')
            ->leftJoin('hardware_components as hc', 'hc.id', '=', 'hm.hardware_component_id')
            ->select(['a.id', 'a.uuid', 'a.asset_tag', 'a.name', 'a.status', 'a.warranty_expiration', 'a.created_at', 'hc.component_type', 'r.code as room']);
        if ($filters->equipmentType === 'asset') {
            $query->where('a.uuid', $filters->equipmentUuid);
        }

        return [$query, $this->columns([['code', 'Asset tag', 'code'], ['name', 'Name'], ['category', 'Category'], ['room', 'Room', 'code'], ['status', 'Status'], ['warranty', 'Warranty until'], ['registered', 'Registered']]),
            fn (object $r): array => [
                'row' => [(string) $r->asset_tag, (string) ($r->name ?? ''), $r->component_type !== null ? (ComponentType::tryFrom((string) $r->component_type)?->label() ?? (string) $r->component_type) : '—', (string) ($r->room ?? '—'), AssetStatus::tryFrom((string) $r->status)?->label() ?? (string) $r->status, $r->warranty_expiration !== null ? substr((string) $r->warranty_expiration, 0, 10) : '—', $this->local($r->created_at, 'Y-m-d')],
                'link' => ['path' => '/app/assets/'.$r->uuid, 'query' => []],
            ], 'a.id'];
    }

    /**
     * @return array{0: Builder, 1: list<array{key: string, label: string, type: string}>, 2: callable, 3: string}
     */
    private function rooms(OverallReportFilters $filters, User $actor): array
    {
        $report = $filters->report->withExtraScope($filters->equipmentParams());
        $problems = $this->directory->reportScope($report->directoryParams(null, ['exclude_duplicates' => '1', 'exclude_cancelled' => '1']), $actor)->toBase()
            ->whereNotNull('tickets.room_id')
            ->groupBy('tickets.room_id')
            ->select('tickets.room_id', DB::raw('count(*) as n'));
        $pcs = DB::table('pc_units')->whereNull('deleted_at')->groupBy('room_id')->select('room_id', DB::raw('count(*) as pcs'));

        $query = DB::table('rooms as r')
            ->joinSub($problems, 'pb', 'pb.room_id', '=', 'r.id')
            ->leftJoinSub($pcs, 'pc', 'pc.room_id', '=', 'r.id')
            ->leftJoin('floors as f', 'f.id', '=', 'r.floor_id')
            ->leftJoin('buildings as b', 'b.id', '=', 'f.building_id')
            ->select(['r.id', 'r.uuid', 'r.code', 'r.name', 'r.room_type', 'r.deleted_at', 'b.name as building', DB::raw('pb.n as problems'), DB::raw('coalesce(pc.pcs, 0) as pcs')]);

        return [$query, $this->columns([['code', 'Code', 'code'], ['name', 'Room'], ['building', 'Building'], ['type', 'Type'], ['problems', 'Problems', 'number'], ['pcs', 'PC units now', 'number']]),
            fn (object $r): array => [
                'row' => [(string) $r->code, (string) $r->name.($r->deleted_at !== null ? ' (archived)' : ''), (string) ($r->building ?? '—'), RoomType::tryFrom((string) $r->room_type)?->label() ?? (string) $r->room_type, (int) $r->problems, (int) $r->pcs],
                'link' => $r->deleted_at === null ? ['path' => '/app/locations/rooms/'.$r->uuid, 'query' => []] : null,
            ], 'r.id'];
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $columns
     * @return list<array{key: string, label: string, type: string}>
     */
    private function columns(array $columns): array
    {
        return array_map(static fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? 'text'], $columns);
    }
}

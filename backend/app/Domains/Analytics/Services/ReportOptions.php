<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Enums\ProblemArea;
use App\Enums\ReportComparison;
use App\Enums\ReportGranularity;
use App\Enums\ReportPeriodPreset;
use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Support\SchoolClock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The vocabulary a report's filter toolbar offers (Phase 2.10): periods,
 * groupings, comparisons, statuses, categories, problem areas and — for a
 * viewer who may see locations — buildings and rooms.
 *
 * Rooms are every live room plus any archived room that still has tickets, so a
 * report can be narrowed to a room that has since been archived; labels are
 * the "Building · Floor · Room" form the location pickers use. Cached for 60
 * seconds on the dashboard version and the viewer's visible domains.
 */
final class ReportOptions
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly ReportsAccess $access,
        private readonly TicketOverviewQuery $query,
        private readonly AdminDashboardSignal $signal,
    ) {}

    /** @return array<string, mixed> */
    public function forTickets(User $actor): array
    {
        $domains = $this->access->visibleDomains($actor);
        $withLocations = in_array(ReportsAccess::DOMAIN_LOCATIONS, $domains, true);

        /** @var array<string, mixed> */
        return Cache::remember(
            sprintf('analytics:report-options:v%d:%s', $this->signal->version(), implode(',', $domains)),
            self::TTL_SECONDS,
            fn (): array => $this->compose($withLocations),
        );
    }

    /** @return array<string, mixed> */
    private function compose(bool $withLocations): array
    {
        $today = SchoolClock::today();
        $recordsStart = $this->query->ticketsStartAt();

        return [
            'timezone' => SchoolClock::TIMEZONE,
            'timezone_label' => sprintf('%s (UTC%s)', SchoolClock::TIMEZONE, $today->format('P')),
            'today' => $today->format('Y-m-d'),
            'data_starts_at' => $recordsStart?->format('Y-m-d'),
            'since_year' => [
                'min' => max(2000, $recordsStart !== null ? $recordsStart->year : $today->year),
                'max' => $today->year,
            ],
            'periods' => ReportPeriodPreset::options(),
            'granularities' => array_map(fn (ReportGranularity $g): array => [
                'value' => $g->value,
                'label' => $g->label(),
                'max_buckets' => $g === ReportGranularity::Auto ? null : $g->maxBuckets(),
            ], ReportGranularity::cases()),
            'comparisons' => ReportComparison::options(),
            'statuses' => DB::table('ticket_statuses')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (object $s): array => [
                    'value' => (string) $s->slug,
                    'label' => (string) $s->name,
                    'is_open' => (bool) $s->is_open,
                    'is_terminal' => (bool) $s->is_terminal,
                ])->all(),
            'categories' => DB::table('ticket_categories')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (object $c): array => [
                    'value' => (string) $c->slug,
                    'label' => (string) $c->name,
                    'problem_area' => (string) $c->problem_area,
                    'is_active' => (bool) $c->is_active,
                ])->all(),
            'problem_areas' => ProblemArea::options(),
            'location_types' => $withLocations ? [
                ['value' => 'all', 'label' => 'All locations'],
                ['value' => RoomType::Laboratory->value, 'label' => 'All laboratories'],
                ['value' => RoomType::Office->value, 'label' => 'All offices'],
            ] : [],
            ...($withLocations ? $this->locations() : ['buildings' => [], 'rooms' => []]),
        ];
    }

    /** @return array{buildings: list<array<string, mixed>>, rooms: list<array<string, mixed>>} */
    private function locations(): array
    {
        $rooms = Room::withTrashed()
            ->with([
                'floor' => fn ($q) => $q->withTrashed()->select(['id', 'uuid', 'name', 'floor_number', 'building_id']),
                'floor.building' => fn ($q) => $q->withTrashed()->select(['id', 'uuid', 'name', 'code', 'is_active', 'deleted_at']),
            ])
            ->where(fn ($q) => $q->whereNull('rooms.deleted_at')
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('tickets')->whereColumn('tickets.room_id', 'rooms.id')))
            ->get(['id', 'uuid', 'name', 'code', 'room_type', 'floor_id', 'is_active', 'deleted_at']);

        $buildings = [];
        $options = [];

        foreach ($rooms as $room) {
            $floor = $room->floor;
            $building = $floor?->building;

            if ($building instanceof Building) {
                $buildings[$building->uuid] = [
                    'id' => $building->uuid,
                    'name' => $building->name,
                    'code' => $building->code,
                    'archived' => $building->deleted_at !== null,
                    'inactive' => ! $building->is_active,
                ];
            }

            $options[] = [
                'id' => $room->uuid,
                'name' => $room->name,
                'code' => $room->code,
                'room_type' => $room->room_type->value,
                'label' => implode(' · ', array_values(array_filter([
                    $building?->name,
                    $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
                    $room->name,
                ]))),
                'building_id' => $building?->uuid,
                'archived' => $room->deleted_at !== null,
                'inactive' => ! $room->is_active,
            ];
        }

        usort($options, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        $buildings = array_values($buildings);
        usort($buildings, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['buildings' => $buildings, 'rooms' => $options];
    }
}

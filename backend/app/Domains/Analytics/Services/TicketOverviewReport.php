<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportKpi;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Enums\ProblemArea;
use App\Enums\ReportContentProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SchoolClock;

/**
 * The ticket overview as document sections (Phase 2.10, SDD DD-91).
 *
 * Built from the very payload `TicketOverview::build()` returns to the
 * dashboard — the same cache entry, when it is warm — so a download, an email
 * attachment and a printout say exactly what the screen said. Nothing here
 * counts anything: it only puts the payload's figures into tables, charts and
 * words. The one query of its own is the optional records table, which reads
 * the same `reportScope()` population the figures count.
 */
final class TicketOverviewReport
{
    public function __construct(
        private readonly TicketOverview $overview,
        private readonly TicketDirectoryQuery $directory,
        private readonly ReportsAccess $access,
    ) {}

    /**
     * @return array{payload: array{data: array<string, mixed>, meta: array<string, mixed>}, sections: list<ReportDocumentSection>, records_included: bool}
     */
    public function sections(ReportFilters $filters, User $actor, ReportBuildOptions $options): array
    {
        $payload = $this->overview->build($filters, $actor);
        $data = $payload['data'];
        $meta = $payload['meta'];
        $identifiersOnly = $options->profile === ReportContentProfile::Identifiers;

        $sections = [
            $this->headline($data, $meta),
            $this->overTime($data, $meta),
            $this->statuses($data, $meta),
        ];

        if (is_array($data['locations'] ?? null)) {
            $sections[] = $this->locations($data['locations'], $identifiersOnly);
        }

        $recordsIncluded = $options->includeRecords && $this->access->canExportTicketRecords($actor);

        if ($recordsIncluded) {
            $sections[] = $this->recordsSection($filters, $actor, $options, $identifiersOnly);
        }

        return ['payload' => $payload, 'sections' => $sections, 'records_included' => $recordsIncluded];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function headline(array $data, array $meta): ReportDocumentSection
    {
        $kpis = $data['kpis'];
        $reported = $kpis['reported'];
        $compared = (bool) ($meta['comparison']['available'] ?? false);
        $comparisonLabel = $compared ? (string) ($meta['comparison']['range_label'] ?? '') : null;

        $reportedDetail = array_values(array_filter([
            $compared && $reported['comparison'] !== null ? $this->changeText($reported, $comparisonLabel) : null,
            $this->includedText((int) $reported['duplicates'], (int) $reported['cancelled']),
        ]));

        $items = [
            new ReportKpi('Reported', $this->number((int) $reported['value']), $reportedDetail !== [] ? implode(' · ', $reportedDetail) : null),
        ];

        if (is_array($kpis['daily_average'] ?? null)) {
            $average = $kpis['daily_average'];
            $items[] = new ReportKpi(
                'Daily average',
                number_format((float) $average['value'], 1),
                sprintf('Over %d days', (int) $average['days']).($average['comparison'] !== null ? sprintf(' · %s per day before', number_format((float) $average['comparison'], 1)) : ''),
            );
        }

        $open = $kpis['open_now'];
        $items[] = new ReportKpi(
            'Still open',
            $this->number((int) $open['value']),
            trim(($open['share_pct'] !== null ? $this->percent((float) $open['share_pct']).' of reported' : '')
                .((int) $open['awaiting_confirmation'] > 0 ? sprintf(' · %d awaiting the reporter\'s confirmation', (int) $open['awaiting_confirmation']) : ''), ' ·'),
        );
        $items[] = new ReportKpi('Resolved in period', $this->number((int) $kpis['resolved_in_period']['value']), 'Moved to Resolved in the period, whenever reported');

        $table = ReportTable::make(
            'headline',
            'Headline figures',
            [['figure', 'Figure'], ['value', 'Value', ReportTable::TYPE_NUMBER], ['detail', 'Detail']],
            array_map(static fn (ReportKpi $k): array => [$k->label, $k->value, $k->detail ?? ''], $items),
        );

        return new ReportDocumentSection(
            key: 'headline',
            title: 'Headline figures',
            intro: 'Tickets reported in the period, by their status now.',
            kpis: $items,
            tables: [$table],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function overTime(array $data, array $meta): ReportDocumentSection
    {
        $series = $data['series'];
        $buckets = $series['buckets'];
        $comparison = is_array($series['comparison'] ?? null) ? $series['comparison']['buckets'] : null;
        $comparisonLabel = (string) ($meta['comparison']['range_label'] ?? 'Comparison');
        $unit = (string) $series['unit_label'];

        $categories = array_map(static fn (array $b): string => (string) $b['label'], $buckets);
        $current = array_map(static fn (array $b): ?int => $b['count'] === null ? null : (int) $b['count'], $buckets);
        $chartSeries = [['name' => 'This period', 'values' => $current, 'style' => 'current']];
        $colors = [ReportPalette::CURRENT];

        $columns = [['bucket', ucfirst($unit)], ['current', 'This period', ReportTable::TYPE_NUMBER]];

        if ($comparison !== null) {
            $previous = [];
            foreach (array_keys($buckets) as $i) {
                $previous[] = isset($comparison[$i]) && $comparison[$i]['count'] !== null ? (int) $comparison[$i]['count'] : null;
            }
            $chartSeries[] = ['name' => 'Compared with '.$comparisonLabel, 'values' => $previous, 'style' => 'compare'];
            $colors[] = ReportPalette::COMPARE;
            $columns[] = ['previous', 'Compared period', ReportTable::TYPE_NUMBER];
        }

        $rows = [];
        foreach ($buckets as $i => $bucket) {
            $label = (string) $bucket['label'].($bucket['in_progress'] ? ' (so far)' : ($bucket['partial'] ? ' (part)' : ''));
            $row = [$label, $bucket['count'] === null ? 'Before records' : (int) $bucket['count']];
            if ($comparison !== null) {
                $row[] = isset($comparison[$i]) ? ($comparison[$i]['count'] === null ? 'Before records' : (int) $comparison[$i]['count']) : '';
            }
            $rows[] = $row;
        }

        $notes = [];
        $unplotted = $series['comparison']['unplotted'] ?? null;
        if (is_array($unplotted) && (int) $unplotted['buckets'] > 0) {
            $notes[] = sprintf(
                'The compared period has %d more %s than this one; their %d tickets are in its total but not in the table.',
                (int) $unplotted['buckets'], $unit.((int) $unplotted['buckets'] === 1 ? '' : 's'), (int) $unplotted['count'],
            );
        }

        $single = count($buckets) <= 1;
        $charts = $single ? [] : [new ChartSpec(
            kind: ChartSpec::LINE,
            title: 'Tickets reported over time',
            categories: $categories,
            series: $chartSeries,
            colors: $colors,
            description: sprintf('Tickets reported per %s%s. The figures are in the table below.', $unit, $comparison !== null ? ', with the compared period dashed' : ''),
            tableKey: 'over_time',
        )];

        if ($single) {
            $notes[] = 'A one-day period has no trend to draw; the figure is in the table.';
        }

        return new ReportDocumentSection(
            key: 'over_time',
            title: 'Tickets reported over time',
            intro: sprintf('Tickets reported per %s, by school time (%s).', $unit, SchoolClock::TIMEZONE),
            charts: $charts,
            tables: [ReportTable::make('over_time', 'Tickets reported per '.$unit, $columns, $rows)],
            notes: $notes,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function statuses(array $data, array $meta): ReportDocumentSection
    {
        $distribution = $data['status_distribution'];
        $rows = $distribution['rows'];
        $present = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['count'] > 0));
        $absent = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['count'] === 0));

        $table = ReportTable::make(
            'statuses',
            'Current status',
            [['status', 'Status'], ['count', 'Tickets', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]],
            array_map(fn (array $r): array => [(string) $r['label'], (int) $r['count'], $r['pct'] !== null ? $this->percent((float) $r['pct']) : '—'], $rows),
        );

        $charts = $present === [] ? [] : [new ChartSpec(
            kind: ChartSpec::DONUT,
            title: 'Current status of tickets reported '.(string) ($meta['period']['range_label'] ?? ''),
            categories: array_map(static fn (array $r): string => (string) $r['label'], $present),
            series: [['name' => 'Tickets', 'values' => array_map(static fn (array $r): int => (int) $r['count'], $present), 'style' => 'slices']],
            colors: array_map(static fn (array $r): string => ReportPalette::status((string) $r['slug']), $present),
            description: 'The share of reported tickets in each status now. The figures are in the table below.',
            tableKey: 'statuses',
        )];

        $notes = $absent !== [] ? ['Not present: '.implode(', ', array_map(static fn (array $r): string => (string) $r['label'], $absent)).'.'] : [];

        return new ReportDocumentSection(
            key: 'statuses',
            title: 'Current status',
            intro: sprintf('Where the %s tickets reported in the period stand now.', $this->number((int) $distribution['total'])),
            charts: $charts,
            tables: [$table],
            notes: $notes,
        );
    }

    /**
     * @param  array<string, mixed>  $locations
     */
    private function locations(array $locations, bool $identifiersOnly): ReportDocumentSection
    {
        $types = ReportTable::make(
            'room_types',
            'Problems by room type',
            [['type', 'Room type'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['rooms', 'Rooms with problems', ReportTable::TYPE_NUMBER], ['total', 'Rooms', ReportTable::TYPE_NUMBER], ['per_room', 'Per room', ReportTable::TYPE_NUMBER]],
            array_map(static fn (array $t): array => [
                (string) $t['label'],
                (int) $t['count'],
                $t['rooms_with_problems'] ?? '—',
                $t['rooms_total'] ?? '—',
                $t['per_room'] !== null ? number_format((float) $t['per_room'], 1) : '—',
            ], $locations['by_type']),
        );

        $columns = $identifiersOnly
            ? [['rank', 'Rank', ReportTable::TYPE_NUMBER], ['room', 'Room code', ReportTable::TYPE_CODE], ['type', 'Room type'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['open', 'Still open', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]]
            : [['rank', 'Rank', ReportTable::TYPE_NUMBER], ['room', 'Room'], ['code', 'Code', ReportTable::TYPE_CODE], ['type', 'Room type'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['open', 'Still open', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]];

        $ranking = [];
        foreach ($locations['ranking'] as $row) {
            $room = $row['room'];
            $flags = array_values(array_filter([$room['archived'] ? 'archived' : null, $room['inactive'] ? 'inactive' : null]));
            $suffix = $flags !== [] ? ' ('.implode(', ', $flags).')' : '';
            $share = $row['share_pct'] !== null ? $this->percent((float) $row['share_pct']) : '—';

            $ranking[] = $identifiersOnly
                ? [(int) $row['rank'], (string) $room['code'].$suffix, (string) $room['room_type_label'], (int) $row['count'], (int) $row['open'], $share]
                : [(int) $row['rank'], (string) $room['label'].$suffix, (string) $room['code'], (string) $room['room_type_label'], (int) $row['count'], (int) $row['open'], $share];
        }

        $notes = ['Problems are reported tickets that are neither duplicates nor cancelled, in the room they were reported in.'];
        if (is_array($locations['others'] ?? null)) {
            $notes[] = sprintf('%d more rooms with %d problems are not listed.', (int) $locations['others']['rooms'], (int) $locations['others']['count']);
        }

        $tables = [$types];
        $charts = [];

        if ($ranking !== []) {
            $tables[] = ReportTable::make('rooms', 'Rooms with the most problems', $columns, $ranking);
            $top = array_slice($locations['ranking'], 0, 10);
            $charts[] = new ChartSpec(
                kind: ChartSpec::BAR,
                title: 'Rooms with the most problems',
                categories: array_map(static fn (array $r): string => $identifiersOnly ? (string) $r['room']['code'] : (string) $r['room']['name'], $top),
                series: [['name' => 'Problems', 'values' => array_map(static fn (array $r): int => (int) $r['count'], $top), 'style' => 'current']],
                colors: [ReportPalette::BAR],
                description: 'Problems per room, most first (up to ten). The figures are in the table below.',
                tableKey: 'rooms',
                valueLabel: 'Problems',
            );
        }

        return new ReportDocumentSection(
            key: 'locations',
            title: 'Where problems were reported',
            intro: sprintf('%s problems in total; %s of them in a recorded room.', $this->number((int) $locations['problems_total']), $this->number((int) $locations['located'])),
            charts: $charts,
            tables: $tables,
            notes: $notes,
        );
    }

    /**
     * The overview payload itself, for documents that cite its summary and filters.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function overviewPayload(ReportFilters $filters, User $actor): array
    {
        return $this->overview->build($filters, $actor);
    }

    public function recordsSection(ReportFilters $filters, User $actor, ReportBuildOptions $options, bool $identifiersOnly): ReportDocumentSection
    {
        $scope = $this->directory->reportScope($filters->directoryParams(), $actor);
        $total = (clone $scope)->count();

        $tickets = $scope
            ->with([
                'category:id,slug,name,problem_area',
                'priority:id,slug,name',
                'status:id,slug,name',
                'reportedRoom:id,code,name',
                'pcUnit:id,unit_code',
                'asset:id,asset_tag',
            ])
            ->reorder()
            ->orderBy('tickets.created_at')
            ->orderBy('tickets.id')
            ->limit($options->recordLimit)
            ->get();

        $columns = [['ticket', 'Ticket', ReportTable::TYPE_CODE]];
        if (! $identifiersOnly) {
            $columns[] = ['title', 'Title'];
        }
        array_push(
            $columns,
            ['category', 'Category'],
            ['area', 'Problem area'],
            ['priority', 'Priority'],
            ['status', 'Status'],
            ['room', 'Room code', ReportTable::TYPE_CODE],
            ['equipment', 'Equipment', ReportTable::TYPE_CODE],
            ['reported', 'Reported ('.SchoolClock::TIMEZONE.')'],
            ['resolved', 'Last resolved'],
            ['duplicate', 'Duplicate'],
        );

        $rows = $tickets->map(function (Ticket $ticket) use ($identifiersOnly): array {
            $area = $ticket->category?->problem_area;
            $row = [(string) $ticket->ticket_number];

            if (! $identifiersOnly) {
                $row[] = (string) $ticket->title;
            }

            array_push(
                $row,
                (string) ($ticket->category->name ?? ''),
                $area instanceof ProblemArea ? $area->label() : '',
                (string) ($ticket->priority->name ?? ''),
                (string) ($ticket->status->name ?? ''),
                (string) ($ticket->reportedRoom->code ?? ''),
                (string) ($ticket->pcUnit->unit_code ?? $ticket->asset->asset_tag ?? ''),
                $ticket->created_at?->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d H:i') ?? '',
                $ticket->resolved_at?->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d H:i') ?? '',
                $ticket->duplicate_of_id !== null ? 'Yes' : '',
            );

            return $row;
        })->values()->all();

        $table = new ReportTable(
            key: 'records',
            title: 'Ticket records',
            columns: array_map(static fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? ReportTable::TYPE_TEXT], $columns),
            rows: $rows,
            totalRows: $total,
            truncated: $total > count($rows),
            note: $identifiersOnly ? 'Identifiers only: ticket titles, descriptions and notes are not included.' : null,
            isRecords: true,
        );

        return new ReportDocumentSection(
            key: 'records',
            title: 'Ticket records',
            intro: sprintf('Every ticket counted in "Reported", oldest first (%s).', $this->number($total)),
            tables: [$table],
        );
    }

    /**
     * @param  array<string, mixed>  $kpi
     */
    private function changeText(array $kpi, ?string $comparisonLabel): string
    {
        $difference = (int) $kpi['change'];
        $sign = $difference > 0 ? '+' : ($difference < 0 ? '−' : '±');
        $text = sprintf('%s%d vs %s', $sign, abs($difference), $comparisonLabel ?? 'the compared period');

        if ($kpi['change_pct'] !== null) {
            $text .= sprintf(' (%s%d%%)', $kpi['change_pct'] > 0 ? '+' : ($kpi['change_pct'] < 0 ? '−' : ''), abs((int) $kpi['change_pct']));
        }

        return $text;
    }

    private function includedText(int $duplicates, int $cancelled): ?string
    {
        $parts = array_values(array_filter([
            $duplicates > 0 ? sprintf('%d %s', $duplicates, $duplicates === 1 ? 'duplicate' : 'duplicates') : null,
            $cancelled > 0 ? sprintf('%d cancelled', $cancelled) : null,
        ]));

        return $parts !== [] ? 'incl. '.implode(', ', $parts) : null;
    }

    private function number(int $value): string
    {
        return number_format($value);
    }

    private function percent(float $value): string
    {
        return number_format($value, 1).'%';
    }
}

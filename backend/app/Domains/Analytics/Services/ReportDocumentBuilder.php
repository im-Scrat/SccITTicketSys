<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportDocument;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportPeriod;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\KnowledgeBase\Services\ReportNarrativeGenerator;
use App\Enums\ComponentType;
use App\Enums\MaintenanceClass;
use App\Enums\ReportContentProfile;
use App\Enums\ReportType;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\User;
use App\Support\SchoolClock;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The single source of report document content (Phase 2.10, SDD DD-91).
 *
 * Downloads, email attachments and the print page all come from here, built
 * from the same service payload the screen shows. The renderers lay a
 * {@see ReportDocument} out and never compute or reword a figure, so the four
 * formats and the printout always agree with the dashboard.
 *
 * Access is asserted here, not only at the route: a queued job builds the
 * document minutes after the request, and must refuse if the administrator has
 * lost the right in between.
 */
final class ReportDocumentBuilder
{
    public function __construct(
        private readonly ReportsAccess $access,
        private readonly TicketOverviewReport $ticketOverview,
        private readonly OverallReportService $overall,
        private readonly ReportNarrativeGenerator $narratives,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  the validated request parameters (the overall report reads its own filters from them)
     *
     * @throws AuthorizationException
     */
    public function build(ReportType $type, ReportFilters $filters, User $actor, ReportBuildOptions $options, array $parameters = []): ReportDocument
    {
        $this->access->assertCanExport($actor);

        return match ($type) {
            ReportType::TicketOverview => $this->ticketOverviewDocument($filters, $actor, $options),
            ReportType::Overall => $this->overallDocument(OverallReportFilters::fromValidated($filters, $parameters), $actor, $options),
        };
    }

    /**
     * Every Overall Reports section the viewer may see, from the same builders
     * as the page, plus the ticket records when asked for and permitted.
     */
    private function overallDocument(OverallReportFilters $filters, User $actor, ReportBuildOptions $options): ReportDocument
    {
        $report = $filters->report;
        $sections = $this->overall->documentSections($filters, $actor);
        $recordsIncluded = false;

        if ($options->includeRecords && $this->access->canExportTicketRecords($actor)) {
            $sections[] = $this->ticketOverview->recordsSection(
                $report->withExtraScope($filters->equipmentParams()),
                $actor,
                $options,
                $options->profile === ReportContentProfile::Identifiers,
            );
            $recordsIncluded = true;
        }

        $narrativeId = null;
        if ($options->includeNarrative && $this->access->canViewInsights($actor)) {
            [$insights, $narrativeId] = $this->insightsSection($filters);
            $sections[] = $insights;
        }

        $comparison = $report->comparison !== null && ! $report->hasStatusFilter()
            ? ReportPeriod::rangeText($report->comparison->start, $report->comparison->end)
            : null;
        $tickets = $this->access->canViewTickets($actor) ? $this->ticketOverview->overviewPayload($report->withExtraScope($filters->equipmentParams()), $actor) : null;

        $filterRows = $tickets !== null ? $this->filterRows($tickets['meta']) : [
            ['label' => 'Period', 'value' => $report->period->rangeLabel()],
        ];
        if ($filters->hasEquipment()) {
            $filterRows[] = ['label' => 'Equipment', 'value' => $this->equipmentLabel($filters)];
        }
        if ($filters->maintenanceClasses !== []) {
            $filterRows[] = ['label' => 'Repair class (Repairs only)', 'value' => implode(', ', array_map(static fn (string $c): string => MaintenanceClass::from($c)->label(), $filters->maintenanceClasses))];
        }
        if ($filters->componentType !== null) {
            $filterRows[] = ['label' => 'Component type (Repairs only)', 'value' => ComponentType::tryFrom($filters->componentType)?->label() ?? $filters->componentType];
        }

        return new ReportDocument(
            type: ReportType::Overall,
            title: ReportType::Overall->label(),
            organisation: (string) config('reports.organisation', 'SccIT'),
            periodLabel: $report->period->rangeLabel(),
            comparisonLabel: $comparison,
            timezoneLabel: SchoolClock::TIMEZONE,
            asOfLabel: 'Data as of '.$report->period->asOf->subMinute()->format('j M Y, H:i').' ('.SchoolClock::TIMEZONE.')',
            generatedLabel: 'Generated '.SchoolClock::now()->format('j M Y, H:i').' ('.SchoolClock::TIMEZONE.')',
            filters: $filterRows,
            summary: $tickets !== null ? array_map(static fn (array $s): string => (string) $s['text'], $tickets['data']['summary']['sentences']) : [],
            summaryLabel: 'Calculated from recorded tickets (fixed rules, not AI)',
            caveats: $tickets !== null ? array_values(array_map('strval', $tickets['data']['summary']['caveats'])) : [],
            sections: $sections,
            definitions: array_map(static fn (array $d): array => ['term' => (string) $d['term'], 'definition' => (string) $d['text']], ReportMetrics::ticketDefinitions()),
            limitations: [
                'Times are school time ('.SchoolClock::TIMEZONE.').',
                'Ticket status is each ticket\'s status now. Equipment status, condition and warranty are as of today.',
                'Problems are counted in the room they were reported in; repairs and equipment in their room now.',
                'Recurring patterns and preventive-care candidates are counted by fixed rules, not by AI. AI advisory findings are never a probability.',
                'Cost is recorded without a currency; totals say on how many records it was recorded.',
            ],
            profile: $options->profile,
            recordsIncluded: $recordsIncluded,
            narrativeId: $narrativeId,
        );
    }

    /**
     * The AI insights section of a document (Phase 2.10 R5, SDD DD-95): the
     * recorded facts per question, then — only if an interpretation was
     * completed for exactly this data — its findings, labelled advisory.
     * Otherwise the section says none was generated for exactly this data;
     * an interpretation of other figures is never printed beside these.
     *
     * @return array{0: ReportDocumentSection, 1: int|null}
     */
    private function insightsSection(OverallReportFilters $filters): array
    {
        $packet = $this->narratives->packet($filters);
        $narrative = $this->narratives->completedFor($filters, $packet);
        $data = $packet->toArray();

        $facts = [];
        foreach ($data['questions'] as $key => $q) {
            foreach ($q['facts'] as $fact) {
                $facts[] = [strtoupper((string) $key), (string) $fact['label'], is_int($fact['value']) || is_float($fact['value']) ? $fact['value'] : (string) $fact['value'], (int) $fact['record_count']];
            }
            if (! $q['sufficient']) {
                $facts[] = [strtoupper((string) $key), 'Not enough data: '.(string) $q['reason'], '—', 0];
            }
        }

        $tables = [ReportTable::make('facts', 'Recorded facts (from the records)', [['q', 'Question', ReportTable::TYPE_CODE], ['fact', 'Fact'], ['value', 'Value', ReportTable::TYPE_NUMBER], ['records', 'Records', ReportTable::TYPE_NUMBER]], $facts)];
        $notes = ['Recorded facts come straight from the records. The AI interpretation is advisory and may be wrong; confidence is the model\'s own rating, not a probability.'];

        if ($narrative !== null) {
            $kinds = ['observation' => 'Observation', 'possible_explanation' => 'Possible explanation', 'recommendation' => 'Suggested action'];
            $tables[] = ReportTable::make('interpretation', 'AI interpretation (advisory)', [['q', 'Question', ReportTable::TYPE_CODE], ['kind', 'Kind'], ['text', 'Statement'], ['confidence', 'Confidence']],
                array_map(static fn (array $f): array => [strtoupper((string) $f['question']), $kinds[$f['kind']] ?? (string) $f['kind'], (string) $f['text'], (string) $f['confidence']], $narrative->findings ?? []));
            $notes[] = sprintf('Generated %s by %s.%s', $narrative->generated_at?->setTimezone(SchoolClock::TIMEZONE)->format('j M Y, H:i') ?? '', (string) $narrative->model, $narrative->withheld_count > 0 ? sprintf(' %d statements were withheld because they did not meet the evidence rules.', $narrative->withheld_count) : '');
        } else {
            $notes = ['No AI interpretation was generated for exactly this data, so none is included.', ...$notes];
        }

        return [new ReportDocumentSection(key: 'insights', title: 'AI insights', intro: 'Questions about replacements, problem locations, failing parts, performance issues and preventive care.', tables: $tables, notes: $notes), $narrative?->id];
    }

    private function equipmentLabel(OverallReportFilters $filters): string
    {
        return match ($filters->equipmentType) {
            'pc' => 'PC unit '.(string) PcUnit::withTrashed()->where('uuid', $filters->equipmentUuid)->value('unit_code'),
            'asset' => 'Asset '.(string) Asset::withTrashed()->where('uuid', $filters->equipmentUuid)->value('asset_tag'),
            default => '',
        };
    }

    private function ticketOverviewDocument(ReportFilters $filters, User $actor, ReportBuildOptions $options): ReportDocument
    {
        $built = $this->ticketOverview->sections($filters, $actor, $options);
        ['data' => $data, 'meta' => $meta] = $built['payload'];

        $comparison = ($meta['comparison']['available'] ?? false)
            ? sprintf('%s (%s)', (string) $meta['comparison']['range_label'], (string) $meta['comparison']['description'])
            : null;

        return new ReportDocument(
            type: ReportType::TicketOverview,
            title: ReportType::TicketOverview->label(),
            organisation: (string) config('reports.organisation', 'SccIT'),
            periodLabel: (string) $meta['period']['range_label'],
            comparisonLabel: $comparison,
            timezoneLabel: (string) $meta['timezone_label'],
            asOfLabel: (string) $meta['as_of_label'],
            generatedLabel: 'Generated '.SchoolClock::now()->format('j M Y, H:i').' ('.SchoolClock::TIMEZONE.')',
            filters: $this->filterRows($meta),
            summary: array_map(static fn (array $s): string => (string) $s['text'], $data['summary']['sentences']),
            summaryLabel: 'Calculated from recorded tickets (fixed rules, not AI)',
            caveats: array_values(array_map('strval', $data['summary']['caveats'])),
            sections: $built['sections'],
            definitions: array_map(
                static fn (array $d): array => ['term' => (string) $d['term'], 'definition' => (string) $d['text']],
                $meta['definitions'],
            ),
            limitations: $this->limitations($meta),
            profile: $options->profile,
            recordsIncluded: $built['records_included'],
        );
    }

    /**
     * The filters in effect, in words, for the cover of every document.
     *
     * @param  array<string, mixed>  $meta
     * @return list<array{label: string, value: string}>
     */
    private function filterRows(array $meta): array
    {
        $f = $meta['filters'];
        $join = static fn (array $items, string $key): string => implode(', ', array_map(static fn (array $i): string => (string) $i[$key], $items));

        $location = match (true) {
            is_array($f['room'] ?? null) => (string) $f['room']['label'].($f['room']['archived'] ? ' (archived)' : ''),
            is_array($f['building'] ?? null) => 'All rooms in '.(string) $f['building']['name'],
            is_array($f['room_type'] ?? null) => 'All '.strtolower((string) $f['room_type']['label']),
            default => 'All locations',
        };

        return [
            ['label' => 'Period', 'value' => (string) $meta['period']['range_label']],
            ['label' => 'Compared with', 'value' => ($meta['comparison']['available'] ?? false) ? (string) $meta['comparison']['range_label'] : 'Not compared'],
            ['label' => 'Grouped by', 'value' => ucfirst((string) $meta['granularity']['resolved'])],
            ['label' => 'Location', 'value' => $location],
            ['label' => 'Status now', 'value' => $f['status'] !== [] ? $join($f['status'], 'label') : 'Any status'],
            ['label' => 'Category', 'value' => $f['category'] !== [] ? $join($f['category'], 'label') : 'Any category'],
            ['label' => 'Problem area', 'value' => $f['problem_area'] !== [] ? $join($f['problem_area'], 'label') : 'Any area'],
            ['label' => 'Time zone', 'value' => (string) $meta['timezone_label']],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function limitations(array $meta): array
    {
        $items = [
            'Times are school time ('.SchoolClock::TIMEZONE.'). '.(string) $meta['as_of_label'].'; anything recorded later is not included.',
            'Status is each ticket\'s status now, not at the end of the period.',
            'Location is the room a ticket was reported in, as recorded when it was filed. Equipment may have moved since.',
            'Archived (deleted) tickets are not counted.',
        ];

        if (is_string($meta['data_starts_at'] ?? null)) {
            $items[] = 'Ticket records begin on '.$meta['data_starts_at'].'; earlier periods have no data, which is not the same as no problems.';
        }

        $withoutHistory = (int) ($meta['data_quality']['tickets_without_history'] ?? 0);
        if ($withoutHistory > 0) {
            $items[] = sprintf('%d %s no recorded status history; lifecycle figures cannot include %s.', $withoutHistory, $withoutHistory === 1 ? 'ticket has' : 'tickets have', $withoutHistory === 1 ? 'it' : 'them');
        }

        return $items;
    }
}

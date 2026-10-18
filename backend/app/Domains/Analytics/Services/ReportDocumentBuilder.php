<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportDocument;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Enums\ReportType;
use App\Models\User;
use App\Support\SchoolClock;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

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
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function build(ReportType $type, ReportFilters $filters, User $actor, ReportBuildOptions $options): ReportDocument
    {
        $this->access->assertCanExport($actor);

        return match ($type) {
            ReportType::TicketOverview => $this->ticketOverviewDocument($filters, $actor, $options),
            ReportType::Overall => throw new InvalidArgumentException('The overall report is built from its own sections (Phase 2.10 R4).'),
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

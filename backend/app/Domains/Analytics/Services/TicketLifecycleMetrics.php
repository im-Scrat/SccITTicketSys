<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportPeriod;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ticket figures that come from the lifecycle — `ticket_status_history`, which
 * `TicketLifecycle` writes in the same transaction as every status change —
 * rather than from the report-date cohort (Phase 2.10, SDD DD-88; SRS
 * FR-RPT-005).
 *
 * One definition each, used by the dashboard, Overall Reports, exports and the
 * AI evidence alike. They are **event** counts: "resolved in the period" counts
 * tickets that moved to Resolved during the period whatever day they were
 * reported, so it is never presented as a share of "reported".
 *
 * Every edge of the transition map has a {@see classify()} kind; a test walks
 * the whole map, so a new transition cannot fall outside every figure unseen.
 */
final class TicketLifecycleMetrics
{
    /** The opening row `CreateTicket` writes (no "from" status). */
    public const KIND_CREATION = 'creation';

    /** Into Resolved — by staff, or by the reporter's own FIXED (WP-J). */
    public const KIND_RESOLUTION = 'resolution';

    /** Resolved → Closed: the reporter (or the auto-close, or an admin) confirmed it. */
    public const KIND_CONFIRMATION = 'confirmation';

    /** Back into work after a fix: Resolved → Open/In progress, or a reopened Closed. */
    public const KIND_SENT_BACK = 'sent_back';

    /** Closed without ever being resolved (an administrator closing an open ticket). */
    public const KIND_CLOSED_WITHOUT_RESOLUTION = 'closed_without_resolution';

    /** Withdrawn. */
    public const KIND_CANCELLATION = 'cancellation';

    /** Moving between the working states: assigned, in progress, on hold, back to open. */
    public const KIND_PROGRESS = 'progress';

    public function __construct(private readonly TicketDirectoryQuery $directory) {}

    /**
     * What a single status change means for the report figures.
     *
     * @see TicketLifecycle for the map these edges come from
     */
    public static function classify(?string $from, string $to): string
    {
        return match (true) {
            $from === null => self::KIND_CREATION,
            $to === 'resolved' => self::KIND_RESOLUTION,
            $to === 'cancelled' => self::KIND_CANCELLATION,
            $from === 'resolved' && $to === 'closed' => self::KIND_CONFIRMATION,
            $to === 'closed' => self::KIND_CLOSED_WITHOUT_RESOLUTION,
            $from === 'resolved', $from === 'closed' => self::KIND_SENT_BACK,
            default => self::KIND_PROGRESS,
        };
    }

    /**
     * Distinct tickets that moved to Resolved within the range, whenever they
     * were reported. The location, category and problem-area filters apply (a
     * room's figures are about that room); the report date and the status
     * filter do not, because this counts an event, not the cohort.
     */
    public function resolvedInPeriod(ReportFilters $filters, ReportPeriod $range, User $actor): int
    {
        $scope = $this->directory->reportScope($filters->scopeParams(), $actor)->toBase()->select('tickets.id');

        return (int) DB::table('ticket_status_history as h')
            ->join('ticket_statuses as s', 's.id', '=', 'h.to_status_id')
            ->where('s.slug', 'resolved')
            ->where('h.created_at', '>=', $range->startUtc())
            ->where('h.created_at', '<', $range->endUtc())
            ->whereIn('h.ticket_id', $scope)
            ->distinct()
            ->count('h.ticket_id');
    }
}

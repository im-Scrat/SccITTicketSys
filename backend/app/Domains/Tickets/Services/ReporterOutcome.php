<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Enums\TicketUpdateType;
use App\Models\Ticket;
use App\Models\TicketUpdate;

/**
 * WP-J — what the reporter said after trying the AI's recommendations, for the
 * ticket's **current open period**: `fixed`, `not_fixed`, or nothing yet.
 *
 * Derived from the `ai_analysis` timeline rows WP-J already writes (FIXED via
 * `TicketLifecycle::transition()`, NOT FIXED via `ReportTicketNotFixed`) — no
 * column, no second record of the same fact.
 *
 * "Current open period" starts at the latest move *into* `open` (a reopen, or
 * a ticket returned to the queue): an answer given before it is history, not
 * the reporter's position now. The boundary is compared by **row id**, not by
 * timestamp — `ticket_updates.created_at` has one-second precision (see the
 * WP-F `datetime_precision` finding), so a FIXED and a reopen inside the same
 * second would otherwise be misordered. Ids are strictly increasing within
 * the table, which is exactly the ordering needed.
 */
class ReporterOutcome
{
    public const FIXED = 'fixed';

    public const NOT_FIXED = 'not_fixed';

    /** @return self::FIXED|self::NOT_FIXED|null */
    public function current(Ticket $ticket): ?string
    {
        $periodStart = (int) TicketUpdate::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('update_type', TicketUpdateType::StatusChange->value)
            ->where('metadata->to', 'open')
            ->max('id');

        $latest = TicketUpdate::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('update_type', TicketUpdateType::AiAnalysis->value)
            ->where('user_id', $ticket->reporter_id)
            ->where('id', '>', $periodStart)
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        $metadata = $latest->metadata ?? [];

        return match (true) {
            ($metadata['outcome'] ?? null) === self::NOT_FIXED => self::NOT_FIXED,
            ($metadata['to'] ?? null) === 'resolved' => self::FIXED,
            default => null,
        };
    }
}

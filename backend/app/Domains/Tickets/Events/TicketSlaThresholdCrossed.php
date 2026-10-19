<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised by the scheduled SLA sweep when a ticket crosses a deadline threshold
 * (SRS FR-TKT-017; notification matrix T4).
 *
 * ── Why a stage string rather than a boolean ───────────────────────────────
 *
 * A ticket has two independent clocks — first response and resolution — and each
 * can be *approaching* or *past* its deadline. Four distinct crossings, each of
 * which should be announced exactly once in a ticket's life. Collapsing them
 * into "breached: yes/no" would either re-notify every day the sweep runs or
 * announce only the first of the four.
 *
 * The stage is therefore also the idempotency key: `{ticket}:{stage}` is
 * recorded once per recipient and the daily sweep is free to re-detect it
 * forever without saying it twice.
 */
class TicketSlaThresholdCrossed
{
    use Dispatchable, SerializesModels;

    public const STAGE_RESPONSE_AT_RISK = 'response_at_risk';

    public const STAGE_RESPONSE_BREACHED = 'response_breached';

    public const STAGE_RESOLUTION_AT_RISK = 'resolution_at_risk';

    public const STAGE_RESOLUTION_BREACHED = 'resolution_breached';

    /**
     * @param  self::STAGE_*  $stage
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $stage,
    ) {}

    public function isBreach(): bool
    {
        return in_array($this->stage, [self::STAGE_RESPONSE_BREACHED, self::STAGE_RESOLUTION_BREACHED], true);
    }

    /** "response" or "resolution" — which clock this is about. */
    public function clock(): string
    {
        return str_starts_with($this->stage, 'response') ? 'response' : 'resolution';
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Tickets\Events\TicketSlaThresholdCrossed;
use App\Enums\NotificationTopic;
use App\Models\Ticket;
use App\Models\User;

/**
 * **T4 — a ticket has crossed an SLA threshold** (SRS FR-TKT-017, FR-NOT-003;
 * owner decision, 2026-09-05).
 *
 * Recipients are **the administrators and the assigned technician**, notify-only.
 * The SRS names no recipients for this trigger and OI-06 (automatic escalation)
 * is still open, so the decision was the Client's to make: oversight for the
 * people accountable for the desk, and a prompt for the one person who can
 * actually act. Nothing here escalates, reassigns or changes a priority — OI-06
 * stays closed, and this notification does not quietly implement it.
 *
 * ── Teachers are not recipients, and the content assumes that ──────────────
 *
 * The matrix is explicit that teacher-visible surfaces should not expose SLA
 * internals, and DD-41's redacted projection withholds SLA posture from them
 * for the same reason. So a reporter is never notified of their own ticket's
 * SLA state — not by omission, but because the listener resolves recipients from
 * the administrator audience and the assignment, and neither can be a Teacher.
 *
 * ── Said once per threshold, forever ───────────────────────────────────────
 *
 * The sweep that raises this runs daily and re-detects the same breach every
 * morning until the ticket is resolved. The dedupe key is the ticket and the
 * **stage**, with no date in it, so each of the four crossings is announced
 * exactly once in a ticket's life. That is the difference between an SLA alert
 * and a daily nag, and it is a property of the key rather than of the sweep.
 */
class TicketSlaThresholdNotification extends ProjectNotification
{
    /**
     * @param  TicketSlaThresholdCrossed::STAGE_*  $stage
     */
    public function __construct(
        private readonly Ticket $ticket,
        private readonly string $stage,
        private readonly bool $breach,
        private readonly string $clock,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TicketSlaThreshold;
    }

    /** Ticket + stage, deliberately without a date. See the class docblock. */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->ticket->uuid.':'.$this->stage;
    }

    public function payload(User $notifiable): array
    {
        $this->ticket->loadMissing('priority');

        $due = $this->clock === 'response'
            ? $this->ticket->response_due_at
            : $this->ticket->resolution_due_at;

        return [
            'title' => $this->breach
                ? "Ticket {$this->ticket->ticket_number} has breached its {$this->clock} SLA"
                : "Ticket {$this->ticket->ticket_number} is approaching its {$this->clock} SLA",
            'message' => $this->ticket->title,
            'data' => array_filter([
                'ticket' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
                'priority' => $this->ticket->priority?->slug,
                'stage' => $this->stage,
                'clock' => $this->clock,
                'breached' => $this->breach,
                'due_at' => $due?->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => "/app/tickets/{$this->ticket->uuid}",
        ];
    }
}

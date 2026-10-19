<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Providers\AnalyticsServiceProvider;
use App\Domains\Analytics\Services\AdminDashboardSignal;
use App\Domains\KnowledgeBase\Events\PcPredictionGenerated;
use App\Domains\Maintenance\Events\MaintenanceCompleted;
use App\Domains\Maintenance\Events\MaintenanceRescheduled;
use App\Domains\Maintenance\Events\MaintenanceScheduled;
use App\Domains\Tickets\Events\TicketAssigned;
use App\Domains\Tickets\Events\TicketCommented;
use App\Domains\Tickets\Events\TicketCreated;
use App\Domains\Tickets\Events\TicketEscalatedByReporter;
use App\Domains\Tickets\Events\TicketSlaThresholdCrossed;
use App\Domains\Tickets\Events\TicketStatusChanged;
use App\Domains\WorkSupport\Events\WorkSupportRequestDecided;
use App\Domains\WorkSupport\Events\WorkSupportRequestSubmitted;

/**
 * Phase 2.9 — turns the domain events the administrator dashboard reflects
 * into one thin realtime signal each (registered for exactly these events in
 * {@see AnalyticsServiceProvider}).
 *
 * Synchronous on purpose: it does no I/O of its own beyond a cache increment
 * deferred to commit, and the broadcast it raises is itself queued (on
 * `realtime`) and held until commit.
 */
class SignalAdminDashboard
{
    public function __construct(private readonly AdminDashboardSignal $signal) {}

    public function handle(object $event): void
    {
        [$kind, $subject] = match (true) {
            $event instanceof TicketCreated => ['ticket.created', $event->ticket->uuid],
            $event instanceof TicketStatusChanged => ['ticket.status_changed', $event->ticket->uuid],
            $event instanceof TicketAssigned => ['ticket.assigned', $event->ticket->uuid],
            $event instanceof TicketCommented => ['ticket.commented', $event->ticket->uuid],
            $event instanceof TicketEscalatedByReporter => ['ticket.escalated', $event->ticket->uuid],
            $event instanceof TicketSlaThresholdCrossed => ['ticket.sla', $event->ticket->uuid],
            $event instanceof MaintenanceScheduled => ['maintenance.scheduled', $event->record->uuid],
            $event instanceof MaintenanceRescheduled => ['maintenance.rescheduled', $event->record->uuid],
            $event instanceof MaintenanceCompleted => ['maintenance.completed', $event->record->uuid],
            $event instanceof WorkSupportRequestSubmitted => ['support_request.submitted', $event->request->uuid],
            $event instanceof WorkSupportRequestDecided => ['support_request.decided', $event->request->uuid],
            $event instanceof PcPredictionGenerated => ['prediction.generated', $event->prediction->uuid],
            default => [null, null],
        };

        if ($kind !== null) {
            $this->signal->send($kind, $subject);
        }
    }
}

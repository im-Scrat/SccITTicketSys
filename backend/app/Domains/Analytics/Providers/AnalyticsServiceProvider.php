<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Providers;

use App\Domains\Analytics\Listeners\RecordReportEmailDelivery;
use App\Domains\Analytics\Listeners\SignalAdminDashboard;
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
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 2.9 — the live administrator dashboard: which domain events move it.
 *
 * Listed explicitly, like the notification trigger map, so "what makes the
 * admin dashboard update without a refresh" has one file to open. Ticket votes
 * have no domain event; ToggleTicketVote signals directly.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const DASHBOARD_EVENTS = [
        TicketCreated::class,
        TicketStatusChanged::class,
        TicketAssigned::class,
        TicketCommented::class,
        TicketEscalatedByReporter::class,
        TicketSlaThresholdCrossed::class,
        MaintenanceScheduled::class,
        MaintenanceRescheduled::class,
        MaintenanceCompleted::class,
        WorkSupportRequestSubmitted::class,
        WorkSupportRequestDecided::class,
        PcPredictionGenerated::class,
    ];

    public function boot(): void
    {
        foreach (self::DASHBOARD_EVENTS as $event) {
            Event::listen($event, SignalAdminDashboard::class);
        }

        // Phase 2.10 R3 — what happened to each emailed report copy (SDD DD-92).
        Event::listen(NotificationSent::class, RecordReportEmailDelivery::class);
        Event::listen(NotificationFailed::class, RecordReportEmailDelivery::class);
    }
}

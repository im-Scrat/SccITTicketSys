<?php

declare(strict_types=1);

namespace App\Domains\Administration\Providers;

use App\Domains\Administration\Events\AnnouncementPublished;
use App\Domains\Administration\Listeners\NotifyOnAnnouncementPublished;
use App\Domains\Administration\Listeners\NotifyOnMaintenanceRescheduled;
use App\Domains\Administration\Listeners\NotifyOnMaintenanceScheduled;
use App\Domains\Administration\Listeners\NotifyOnPcPredictionGenerated;
use App\Domains\Administration\Listeners\NotifyOnPreventiveMaintenanceDue;
use App\Domains\Administration\Listeners\NotifyOnTicketAssigned;
use App\Domains\Administration\Listeners\NotifyOnTicketCommented;
use App\Domains\Administration\Listeners\NotifyOnTicketSlaThreshold;
use App\Domains\Administration\Listeners\NotifyOnTicketStatusChanged;
use App\Domains\Administration\Listeners\NotifyOnWorkSupportRequestDecided;
use App\Domains\Administration\Listeners\NotifyOnWorkSupportRequestSubmitted;
use App\Domains\Administration\Notifications\Channels\DatabaseChannel;
use App\Domains\Administration\Policies\AnnouncementPolicy;
use App\Domains\Administration\Policies\NotificationPolicy;
use App\Domains\KnowledgeBase\Events\PcPredictionGenerated;
use App\Domains\Maintenance\Events\MaintenanceRescheduled;
use App\Domains\Maintenance\Events\MaintenanceScheduled;
use App\Domains\Maintenance\Events\PreventiveMaintenanceDue;
use App\Domains\Tickets\Events\TicketAssigned;
use App\Domains\Tickets\Events\TicketCommented;
use App\Domains\Tickets\Events\TicketSlaThresholdCrossed;
use App\Domains\Tickets\Events\TicketStatusChanged;
use App\Domains\WorkSupport\Events\WorkSupportRequestDecided;
use App\Domains\WorkSupport\Events\WorkSupportRequestSubmitted;
use App\Models\Announcement;
use App\Models\Notification as NotificationRecord;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

/**
 * **Everything WP-2.7a wires into the framework, in one file.**
 *
 * Three registrations, and each is deliberate about *where* it happens rather
 * than merely *that* it happens.
 *
 * ── 1. The project database channel (SDD DD-52) ───────────────────────────
 *
 * `Notification::resolved()` extends the `ChannelManager` over the framework's
 * own `database` name. Overriding the name rather than inventing a new one is
 * what keeps `via()` returning the string Laravel's own tooling expects, so a
 * future notification written by someone who has never read DD-52 still lands in
 * the right place. The stock driver is unreachable afterwards, which is the
 * point: this project's `notifications` table cannot accept it.
 *
 * ── 2. The event map is explicit, not discovered ──────────────────────────
 *
 * Laravel 13 can auto-discover listeners by type-hint. This project registers
 * them by hand for the same reason `bootstrap/app.php` registers domain commands
 * by hand: discovery only looks in framework-shaped folders, and — more
 * usefully — an explicit map is a readable statement of which triggers exist.
 * The list below is the FR-NOT-003 matrix in code, and a missing line is a
 * missing notification that a reader can actually see is missing.
 *
 * **T7 (low-stock reorder) and T8 (procurement approval/rejection) are absent by
 * decision.** They depend on `consumables` and `procurement_requests`, which
 * WP-2.4b has not built. Wiring an event nothing can raise would claim coverage
 * this system does not have.
 *
 * **T12 (new announcement) is wired by WP-2.7c**, and it cost the prediction
 * above exactly: one event, one listener and one topic case, with the channel
 * driver, dispatcher, preference gate and policy untouched. It is the only
 * broadcast in the map — recipients come from the announcement's audience
 * column rather than from a record's relations — and the only notification that
 * *refuses* a channel, because D5 (SRS §22.1) says publishing sends no email.
 *
 * ── 3. The notification policy ────────────────────────────────────────────
 *
 * Registered here rather than in `AppServiceProvider` so that the whole
 * notification boundary — who may read one, and who may mark one read — reads
 * beside the triggers that create them.
 */
class NotificationServiceProvider extends ServiceProvider
{
    /**
     * The FR-NOT-003 trigger map.
     *
     * @var array<class-string, class-string>
     */
    private const LISTENERS = [
        // T1 — ticket assigned / reassigned
        TicketAssigned::class => NotifyOnTicketAssigned::class,
        // T2 — ticket status change
        TicketStatusChanged::class => NotifyOnTicketStatusChanged::class,
        // T3 — new comment on a followed / owned ticket
        TicketCommented::class => NotifyOnTicketCommented::class,
        // T4 — SLA breach / nearing breach
        TicketSlaThresholdCrossed::class => NotifyOnTicketSlaThreshold::class,
        // T5 — maintenance scheduled
        MaintenanceScheduled::class => NotifyOnMaintenanceScheduled::class,
        // T5 — maintenance due / overdue (the daily sweep)
        PreventiveMaintenanceDue::class => NotifyOnPreventiveMaintenanceDue::class,
        // T6 — maintenance rescheduled
        MaintenanceRescheduled::class => NotifyOnMaintenanceRescheduled::class,
        // T9 — work support request submitted
        WorkSupportRequestSubmitted::class => NotifyOnWorkSupportRequestSubmitted::class,
        // T10 — administrator decision on a work support request
        WorkSupportRequestDecided::class => NotifyOnWorkSupportRequestDecided::class,
        // T12 — an announcement was published (WP-2.7c)
        AnnouncementPublished::class => NotifyOnAnnouncementPublished::class,
        // WP-M — a predictive-maintenance finding awaits administrator review
        PcPredictionGenerated::class => NotifyOnPcPredictionGenerated::class,
    ];

    public function boot(): void
    {
        $this->registerChannel();
        $this->registerListeners();

        Gate::policy(NotificationRecord::class, NotificationPolicy::class);
        // WP-2.7c: reading is audience membership, managing is one ability.
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
    }

    private function registerChannel(): void
    {
        /*
         * The container is captured rather than reached through `$this->app`:
         * `Manager::callCustomCreator()` invokes the closure through the manager,
         * so `$this` inside it is not reliably this provider.
         */
        $container = $this->app;

        Notification::resolved(function (ChannelManager $manager) use ($container): void {
            $manager->extend('database', fn (): DatabaseChannel => $container->make(DatabaseChannel::class));
        });
    }

    private function registerListeners(): void
    {
        foreach (self::LISTENERS as $event => $listener) {
            Event::listen($event, $listener);
        }
    }
}

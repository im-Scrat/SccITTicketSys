<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * **Which trigger produced a notification** (SRS FR-NOT-003; WP-2.7a).
 *
 * Deliberately *not* the same thing as {@see NotificationType}, and this is the
 * distinction the whole notification layer rests on:
 *
 *  - `NotificationType` is the **display and preference** vocabulary the
 *    Client's schema fixes at nine values behind a CHECK constraint, and
 *    FR-NOT-005 names it for *filtering*. Several triggers share one type —
 *    a maintenance visit falling due and a support request being decided are
 *    both `maintenance` to a user filtering their list.
 *  - `NotificationTopic` is the **trigger identity**: exactly one case per row
 *    of the approved FR-NOT-003 recipient matrix. It decides the content rules,
 *    the recipients and the idempotency key.
 *
 * Collapsing the two would mean either widening a baselined CHECK constraint to
 * carry a dozen more values — a schema change no requirement asks for — or
 * losing the ability to say which of three maintenance triggers fired. So the
 * topic lives in PHP and travels in `notifications.data.topic`, where WP-2.7b
 * can group by it and no CHECK constraint has to move.
 *
 * The two blocked triggers of the matrix — **T7 low-stock reorder** and **T8
 * procurement approval/rejection** — have no case here. They depend on
 * `consumables` and `procurement_requests`, which WP-2.4b has not built, and a
 * topic with nothing able to raise it would claim coverage the system does not
 * have.
 */
enum NotificationTopic: string
{
    use HasValues;

    /** T1 — a ticket was assigned to a technician. */
    case TicketAssigned = 'ticket.assigned';

    /** T1 — a ticket was taken away from the previously assigned technician. */
    case TicketReassigned = 'ticket.reassigned';

    /** T2 — a ticket moved between statuses. */
    case TicketStatusChanged = 'ticket.status_changed';

    /** T3 — a comment was added to a ticket the recipient participates in. */
    case TicketCommented = 'ticket.commented';

    /** T4 — a ticket crossed an SLA threshold (at risk, or breached). */
    case TicketSlaThreshold = 'ticket.sla_threshold';

    /** T5 — a maintenance visit was scheduled against a technician. */
    case MaintenanceScheduled = 'maintenance.scheduled';

    /** T5 — a scheduled visit is overdue or falls inside the reminder window. */
    case MaintenanceDue = 'maintenance.due';

    /** T6 — a scheduled visit moved to a different date. */
    case MaintenanceRescheduled = 'maintenance.rescheduled';

    /** T9 — a technician raised a work support request. */
    case WorkSupportSubmitted = 'work_support.submitted';

    /** T10 — an administrator decided a work support request. */
    case WorkSupportDecided = 'work_support.decided';

    /** T11 — an account was locked after repeated failed sign-ins. */
    case AccountLocked = 'account.locked';

    /**
     * T12 — an administrator published an announcement.
     *
     * The one broadcast in the matrix: every other topic is addressed to people
     * because of something they did or own, and this one goes to an audience
     * because of who they are (FR-NOT-010). That is why it is also the only
     * topic whose notification excludes a channel outright — WP-2.7c decision D1 says
     * publishing creates an in-app notification and sends no email.
     */
    case AnnouncementPublished = 'announcement.published';

    /**
     * The {@see NotificationType} this topic is filed under.
     *
     * The mapping is many-to-one on purpose: nine types is what the baselined
     * CHECK constraint allows, and it is the granularity FR-NOT-002 gives users
     * for turning channels off. A user who silences `maintenance` email is
     * saying "stop mailing me about machines", not "stop mailing me about
     * rescheduling specifically".
     */
    public function type(): NotificationType
    {
        return match ($this) {
            self::TicketAssigned, self::TicketReassigned => NotificationType::Assignment,
            self::TicketStatusChanged, self::TicketCommented => NotificationType::TicketUpdate,
            // Warning rather than Error: a breach is a fact about a deadline,
            // not a fault in the system, and `error` is what the UI will colour
            // for something that actually went wrong.
            self::TicketSlaThreshold => NotificationType::Warning,
            self::MaintenanceScheduled,
            self::MaintenanceDue,
            self::MaintenanceRescheduled,
            self::WorkSupportSubmitted,
            self::WorkSupportDecided => NotificationType::Maintenance,
            self::AccountLocked => NotificationType::System,
            self::AnnouncementPublished => NotificationType::Announcement,
        };
    }
}

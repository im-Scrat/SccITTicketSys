<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\NotificationType;

/**
 * WP-2.7a — the topic/type split (SRS FR-NOT-003/005).
 *
 * `NotificationTopic` names the trigger; `NotificationType` is the nine-value
 * display and preference vocabulary the baselined schema fixes behind a CHECK
 * constraint. The tests below hold the seam between them, because it is a seam
 * that can rot silently: a new topic mapped to a type outside the enum would
 * pass every unit test in the notification layer and fail at the database, in a
 * queue worker, in production.
 */
it('maps every topic to a type the database will accept', function (): void {
    foreach (NotificationTopic::cases() as $topic) {
        expect(NotificationType::values())->toContain($topic->type()->value);
    }
})->group('notifications');

it('files each trigger under the vocabulary a user would filter by', function (): void {
    expect(NotificationTopic::TicketAssigned->type())->toBe(NotificationType::Assignment)
        ->and(NotificationTopic::TicketReassigned->type())->toBe(NotificationType::Assignment)
        ->and(NotificationTopic::TicketStatusChanged->type())->toBe(NotificationType::TicketUpdate)
        ->and(NotificationTopic::TicketCommented->type())->toBe(NotificationType::TicketUpdate)
        ->and(NotificationTopic::TicketSlaThreshold->type())->toBe(NotificationType::Warning)
        ->and(NotificationTopic::MaintenanceScheduled->type())->toBe(NotificationType::Maintenance)
        ->and(NotificationTopic::MaintenanceDue->type())->toBe(NotificationType::Maintenance)
        ->and(NotificationTopic::MaintenanceRescheduled->type())->toBe(NotificationType::Maintenance)
        ->and(NotificationTopic::WorkSupportSubmitted->type())->toBe(NotificationType::Maintenance)
        ->and(NotificationTopic::WorkSupportDecided->type())->toBe(NotificationType::Maintenance)
        ->and(NotificationTopic::AccountLocked->type())->toBe(NotificationType::System);
})->group('notifications');

/**
 * The two triggers WP-2.4b blocks.
 *
 * T7 (low-stock reorder) needs `consumables` and a stock ledger; T8
 * (procurement approval/rejection) needs `procurement_requests`. Neither has
 * domain code, so neither has a topic — and this test is here so that adding one
 * speculatively fails, rather than quietly implying FR-NOT-003 is fully covered.
 * When WP-2.4b lands, this expectation is the reminder to revisit the matrix.
 */
it('does not claim the two triggers WP-2.4b blocks', function (): void {
    expect(NotificationTopic::values())
        ->not->toContain('inventory.low_stock')
        ->not->toContain('procurement.decided');
})->group('notifications');

it('offers exactly the three channels the schema constrains', function (): void {
    expect(NotificationChannel::values())->toBe(['in_app', 'email', 'digest']);
})->group('notifications');

/**
 * The distinction WP-2.7e introduced, guarded.
 *
 * `Digest` is a preference a user can set, not a route a notification takes:
 * it aggregates rows that already exist (SRS FR-NOT-008, SDD DD-63). If a
 * future channel is added without deciding which kind it is, `driver()` stops
 * compiling -- and if someone gives `Digest` a driver, this fails rather than
 * the dispatch path failing for every user at runtime.
 */
it('routes the dispatchable channels and refuses to route the digest', function (): void {
    expect(NotificationChannel::InApp->driver())->toBe('database')
        ->and(NotificationChannel::Email->driver())->toBe('mail')
        ->and(NotificationChannel::Digest->driver())->toBeNull();
})->group('notifications');

it('treats digest as the one channel that is not dispatched to', function (): void {
    expect(NotificationChannel::InApp->isDispatchable())->toBeTrue()
        ->and(NotificationChannel::Email->isDispatchable())->toBeTrue()
        ->and(NotificationChannel::Digest->isDispatchable())->toBeFalse();
})->group('notifications');

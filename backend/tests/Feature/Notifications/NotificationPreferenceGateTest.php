<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Administration\Services\NotificationPreferences;
use App\Domains\Identity\Notifications\AccountLocked;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * WP-2.7a — **the preference gate** (SRS FR-NOT-002).
 *
 * `notification_preferences` is a user × channel × type matrix with
 * `is_enabled` defaulting to true, which makes the model **opt-out**: a user
 * receives everything until they turn something off.
 *
 * The load-bearing test in this file is the first one. An absent row means
 * *enabled*, and a gate that read it as *disabled* would silence every
 * notification for every user who has never opened the preferences screen —
 * which, on the day this ships, is every user. Nothing would error. The system
 * would simply go quiet, and the quiet would look like an absence of events.
 */
beforeEach(function (): void {
    seedRbac();
    $this->user = userWithRole('technician');
    $this->gate = app(NotificationPreferences::class);
});

/** A bare notification of a chosen topic, for exercising `via()`. */
function notificationFor(NotificationTopic $topic): ProjectNotification
{
    return new class($topic) extends ProjectNotification
    {
        public function __construct(private readonly NotificationTopic $chosen)
        {
            parent::__construct();
        }

        public function topic(): NotificationTopic
        {
            return $this->chosen;
        }

        public function payload(User $notifiable): array
        {
            return ['title' => 'T', 'message' => null, 'data' => [], 'action_url' => null];
        }
    };
}

it('treats an absent preference row as enabled', function (): void {
    expect(NotificationPreference::query()->count())->toBe(0)
        ->and($this->gate->allows($this->user, NotificationChannel::InApp, NotificationType::Assignment))->toBeTrue()
        ->and($this->gate->allows($this->user, NotificationChannel::Email, NotificationType::Assignment))->toBeTrue();
});

it('suppresses a channel the user has switched off', function (): void {
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::Assignment, false);

    expect($this->gate->allows($this->user, NotificationChannel::Email, NotificationType::Assignment))->toBeFalse()
        // The other channel for the same type is untouched.
        ->and($this->gate->allows($this->user, NotificationChannel::InApp, NotificationType::Assignment))->toBeTrue();
});

it('keeps one type independent of another', function (): void {
    $this->gate->set($this->user, NotificationChannel::InApp, NotificationType::Maintenance, false);

    expect($this->gate->allows($this->user, NotificationChannel::InApp, NotificationType::Maintenance))->toBeFalse()
        ->and($this->gate->allows($this->user, NotificationChannel::InApp, NotificationType::TicketUpdate))->toBeTrue();
});

it('keeps one user independent of another', function (): void {
    $other = userWithRole('technician');
    $this->gate->set($this->user, NotificationChannel::InApp, NotificationType::Assignment, false);

    expect($this->gate->allows($other, NotificationChannel::InApp, NotificationType::Assignment))->toBeTrue();
});

it('records one row per cell however often a preference is set', function (): void {
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::System, false);
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::System, true);

    expect(NotificationPreference::query()->count())->toBe(1)
        ->and($this->gate->allows($this->user, NotificationChannel::Email, NotificationType::System))->toBeTrue();
});

it('answers the whole matrix, gaps filled in as enabled', function (): void {
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::Warning, false);

    $matrix = $this->gate->matrix($this->user);

    // Every channel x every type, all present and answered. Derived rather
    // than counted: WP-2.7e's `digest` channel took this from 18 cells to 27,
    // and a hard-coded multiplier would have to be found and changed again for
    // the next one.
    expect($matrix)->toHaveCount(count(NotificationChannel::cases()) * count(NotificationType::cases()))
        ->and(collect($matrix)->where('is_enabled', false)->values()->all())->toBe([
            ['channel' => 'email', 'notification_type' => 'warning', 'is_enabled' => false],
        ]);
});

/* ------------------------------------------------ the gate as via() sees it */

it('routes a notification to both channels by default', function (): void {
    expect(notificationFor(NotificationTopic::TicketAssigned)->via($this->user))
        ->toBe(['database', 'mail']);
});

it('drops the in-app channel when the user has switched that type off', function (): void {
    $this->gate->set($this->user, NotificationChannel::InApp, NotificationType::Assignment, false);

    expect(notificationFor(NotificationTopic::TicketAssigned)->via($this->user))->toBe(['mail']);
});

it('sends a notification nowhere when the user has switched both channels off', function (): void {
    $this->gate->set($this->user, NotificationChannel::InApp, NotificationType::Assignment, false);
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::Assignment, false);

    expect(notificationFor(NotificationTopic::TicketAssigned)->via($this->user))->toBe([]);
});

/**
 * The single exception in the whole layer (matrix T11).
 *
 * A locked-out user cannot sign in to read an in-app message, so the account
 * lockout email bypasses the gate. A security notice a user silenced six months
 * ago is a security notice that does not exist.
 */
it('forces the account lockout email past a preference that would silence it', function (): void {
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::System, false);
    $this->gate->set($this->user, NotificationChannel::InApp, NotificationType::System, false);

    expect((new AccountLocked(900))->via($this->user))->toBe(['mail']);
});

it('does not force any other notification past the gate', function (): void {
    $this->gate->set($this->user, NotificationChannel::Email, NotificationType::Maintenance, false);
    $this->gate->set($this->user, NotificationChannel::InApp, NotificationType::Maintenance, false);

    expect(notificationFor(NotificationTopic::MaintenanceDue)->via($this->user))->toBe([]);
});

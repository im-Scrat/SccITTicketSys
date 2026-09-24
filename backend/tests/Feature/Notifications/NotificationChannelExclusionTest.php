<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Administration\Services\NotificationPreferences;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\User;

/**
 * WP-2.7c-1 — **channel exclusion**: the D5 policy (SRS §22.1), by the
 * mechanism WP-2.7c D1 approved.
 *
 * The design decision this implements is not yet numbered in the SDD: WP-2.7c
 * was authorised as implementation only, and documentation reconciliation is a
 * separate stage. It will become the next free DD-* entry there.
 *
 * `via()` decides where a notification goes, and until WP-2.7c it could only
 * ever say *yes* more loudly: `forcedChannels()` bypassed the preference gate,
 * and nothing could refuse a channel. That is fine for a notification addressed
 * to one person about their own work, and wrong for a **broadcast**.
 *
 * Announcements go to a whole audience at once, and D5 (SRS §22.1) is that
 * publishing one sends **no email**. Preferences cannot deliver that: they are
 * opt-out, so an absent row means *enabled* and a fresh system would mail
 * everybody. The guarantee therefore lives in the dispatch path, and these
 * tests are what make it a guarantee rather than an intention.
 *
 * The precedence under test is deliberately lopsided:
 *
 *     excluded  >  forced  >  preference
 *
 * Where the two exceptions could contradict each other, the reading that sends
 * *less* wins — because the failure this prevents is mailing an entire school.
 */
beforeEach(function (): void {
    seedRbac();
    $this->user = userWithRole('teacher');
});

/**
 * A notification whose channel lists are supplied per test, so the precedence
 * rule is exercised directly rather than through a real trigger.
 *
 * @param  list<NotificationChannel>  $forced
 * @param  list<NotificationChannel>  $excluded
 */
function channelProbe(array $forced = [], array $excluded = []): ProjectNotification
{
    return new class($forced, $excluded) extends ProjectNotification
    {
        /**
         * @param  list<NotificationChannel>  $forced
         * @param  list<NotificationChannel>  $excluded
         */
        public function __construct(
            private readonly array $forced,
            private readonly array $excluded,
        ) {
            parent::__construct();
        }

        public function topic(): NotificationTopic
        {
            return NotificationTopic::AnnouncementPublished;
        }

        public function payload(User $notifiable): array
        {
            return ['title' => 'Probe', 'message' => null, 'data' => [], 'action_url' => null];
        }

        public function forcedChannels(): array
        {
            return $this->forced;
        }

        public function excludedChannels(): array
        {
            return $this->excluded;
        }
    };
}

it('defaults to excluding nothing, so existing notifications are unaffected', function (): void {
    // The whole change has to be inert for the nine triggers that shipped in
    // WP-2.7a. With no exclusion declared, both channels remain available.
    $channels = channelProbe()->via($this->user);

    expect($channels)->toContain('database')
        ->and($channels)->toContain('mail');
});

it('refuses an excluded channel even when the preference allows it', function (): void {
    // The live case: preferences are opt-out, so email is enabled by default.
    // This is exactly the state in which an announcement would otherwise be
    // mailed to the entire audience.
    $channels = channelProbe(excluded: [NotificationChannel::Email])->via($this->user);

    expect($channels)->toContain('database')
        ->and($channels)->not->toContain('mail');
});

it('lets exclusion outrank a forced channel', function (): void {
    // The two exceptions contradict each other here. The safe reading of
    // "this must not be emailed" is the one that sends no email.
    $channels = channelProbe(
        forced: [NotificationChannel::Email],
        excluded: [NotificationChannel::Email],
    )->via($this->user);

    expect($channels)->not->toContain('mail');
});

it('still honours a forced channel when nothing excludes it', function (): void {
    // The account-lockout guarantee (matrix T11) must survive this change: a
    // user who has switched their system email off is still mailed.
    app(NotificationPreferences::class)->set(
        $this->user,
        NotificationChannel::Email,
        NotificationTopic::AnnouncementPublished->type(),
        false,
    );

    $channels = channelProbe(forced: [NotificationChannel::Email])->via($this->user);

    expect($channels)->toContain('mail');
});

it('still honours a disabled preference when nothing forces or excludes it', function (): void {
    app(NotificationPreferences::class)->set(
        $this->user,
        NotificationChannel::Email,
        NotificationTopic::AnnouncementPublished->type(),
        false,
    );

    $channels = channelProbe()->via($this->user);

    expect($channels)->toContain('database')
        ->and($channels)->not->toContain('mail');
});

it('can exclude the in-app channel too, leaving mail only', function (): void {
    // Nothing in the product does this today. It is asserted so the mechanism
    // is symmetric rather than a special case bolted on for one channel.
    $channels = channelProbe(excluded: [NotificationChannel::InApp])->via($this->user);

    expect($channels)->not->toContain('database')
        ->and($channels)->toContain('mail');
});

it('sends nothing at all when every channel is excluded', function (): void {
    $channels = channelProbe(excluded: [
        NotificationChannel::InApp,
        NotificationChannel::Email,
    ])->via($this->user);

    expect($channels)->toBe([]);
});

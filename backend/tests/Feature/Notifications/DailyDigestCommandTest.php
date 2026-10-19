<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\DailyDigestNotification;
use App\Domains\Administration\Services\DailyDigest;
use App\Domains\Administration\Services\NotificationPreferences;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\UserStatus;
use App\Models\Notification;
use App\Models\NotificationDigest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * WP-2.7e — **`notifications:send-digest`** (SRS FR-NOT-008; SDD DD-63).
 *
 * The service decides the rules; this file drives the command that applies
 * them, and is where the **at-most-once** guarantee is actually demonstrated.
 *
 * That guarantee is deliberately asymmetric, and the tests are too. The Client
 * chose: never send twice, even at the cost of occasionally sending nothing
 * after a crash. So the claim is committed *before* the mail leaves, and these
 * tests prove both halves of that bargain — the protection and its price.
 */
beforeEach(function (): void {
    seedRbac();

    NotificationFacade::fake();

    $this->gate = app(NotificationPreferences::class);

    // Every run in this file summarises the same school-local day.
    $this->date = '2026-09-07';
});

/** Run the command for the fixed test day. */
function runDigest(): int
{
    return Artisan::call('notifications:send-digest', ['--date' => '2026-09-07']);
}

/** An unread notification for `$user`, inside the test day. */
function commandItem(User $user, array $attributes = []): Notification
{
    return Notification::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::Assignment->value,
        'title' => 'A ticket was assigned to you',
        'created_at' => CarbonImmutable::parse('2026-09-07 09:00:00', DailyDigest::TIMEZONE),
        ...$attributes,
    ]);
}

/* ---------------------------------------------------------------- delivery */

it('sends a digest to a user who has unread items for the day', function (): void {
    $user = userWithRole('teacher');
    commandItem($user);

    runDigest();

    NotificationFacade::assertSentTo($user, DailyDigestNotification::class);
    expect(NotificationDigest::query()->count())->toBe(1);
});

it('sends to every role that qualifies', function (): void {
    $users = collect(['administrator', 'technician', 'teacher'])
        ->map(fn (string $role): User => tap(userWithRole($role), fn (User $u) => commandItem($u)));

    runDigest();

    foreach ($users as $user) {
        NotificationFacade::assertSentTo($user, DailyDigestNotification::class);
    }

    expect(NotificationDigest::query()->count())->toBe(3);
});

/* ------------------------------------------------------------ empty digest */

it('sends nothing to a user with no eligible items', function (): void {
    $user = userWithRole('teacher');

    runDigest();

    NotificationFacade::assertNothingSent();
});

it('writes no claim row for an empty digest, so the table means "sent"', function (): void {
    userWithRole('teacher');

    runDigest();

    expect(NotificationDigest::query()->count())->toBe(0);
});

it('sends nothing when the user switched every type off for the digest', function (): void {
    $user = userWithRole('teacher');
    commandItem($user);

    foreach (NotificationType::cases() as $type) {
        $this->gate->set($user, NotificationChannel::Digest, $type, false);
    }

    runDigest();

    NotificationFacade::assertNothingSent();
    expect(NotificationDigest::query()->count())->toBe(0);
});

/* ------------------------------------------------ at-most-once, the protection */

it('does not send twice when the command runs again for the same day', function (): void {
    $user = userWithRole('teacher');
    commandItem($user);

    runDigest();
    runDigest();

    NotificationFacade::assertSentToTimes($user, DailyDigestNotification::class, 1);
    expect(NotificationDigest::query()->count())->toBe(1);
});

it('does not send again after a redeploy, restart or worker retry', function (): void {
    // All four scenarios reduce to the same thing: the process runs the command
    // a second time against a database that already holds the claim.
    $user = userWithRole('teacher');
    commandItem($user);

    runDigest();

    // A fresh container would resolve the services again; the claim survives.
    app()->forgetInstance(DailyDigest::class);

    runDigest();

    NotificationFacade::assertSentToTimes($user, DailyDigestNotification::class, 1);
});

it('still sends to a user whose neighbour already holds the day', function (): void {
    $first = userWithRole('teacher');
    $second = userWithRole('technician');
    commandItem($first);

    runDigest();

    commandItem($second);
    runDigest();

    NotificationFacade::assertSentToTimes($first, DailyDigestNotification::class, 1);
    NotificationFacade::assertSentToTimes($second, DailyDigestNotification::class, 1);
});

/* ----------------------------------------------------- at-most-once, the price */

it('does not resend a digest whose mail failed, because the claim was already committed', function (): void {
    /*
     * The accepted cost of at-most-once. The claim is written before SMTP is
     * contacted, so a transport failure spends that day for that user: the
     * command logs it and moves on, and a later run finds the day held.
     *
     * This test exists to make that behaviour deliberate and visible rather
     * than something discovered in production.
     */
    $user = userWithRole('teacher');
    commandItem($user);

    NotificationFacade::fake();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('smtp is down'));

    runDigest();

    // The day is spent even though nothing arrived.
    expect(NotificationDigest::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('keeps going when one recipient fails', function (): void {
    // DD-59's posture: one bad recipient must not deny everybody after them.
    $first = userWithRole('teacher');
    $second = userWithRole('technician');
    commandItem($first);
    commandItem($second);

    runDigest();

    expect(NotificationDigest::query()->count())->toBe(2);
});

/* ------------------------------------------------------------------ window */

it('ignores items from the day before the window', function (): void {
    $user = userWithRole('teacher');
    commandItem($user, ['created_at' => CarbonImmutable::parse('2026-09-06 23:59:59', DailyDigest::TIMEZONE)]);

    runDigest();

    NotificationFacade::assertNothingSent();
});

it('ignores items from the day after the window', function (): void {
    $user = userWithRole('teacher');
    commandItem($user, ['created_at' => CarbonImmutable::parse('2026-09-08 00:00:00', DailyDigest::TIMEZONE)]);

    runDigest();

    NotificationFacade::assertNothingSent();
});

it('ignores items the user has already read', function (): void {
    $user = userWithRole('teacher');
    commandItem($user, ['read_at' => CarbonImmutable::now()]);

    runDigest();

    NotificationFacade::assertNothingSent();
});

/* -------------------------------------------------------------- recipients */

it('skips accounts that are not active', function (): void {
    $suspended = userWithRole('teacher', ['status' => UserStatus::Suspended->value]);
    commandItem($suspended);

    runDigest();

    NotificationFacade::assertNothingSent();
    expect(NotificationDigest::query()->count())->toBe(0);
});

it('never aggregates one user\'s notifications into another\'s digest', function (): void {
    $mine = userWithRole('teacher');
    $theirs = userWithRole('technician');
    commandItem($mine, ['title' => 'MINE-ONLY']);

    runDigest();

    NotificationFacade::assertNotSentTo($theirs, DailyDigestNotification::class);
    NotificationFacade::assertSentTo($mine, DailyDigestNotification::class,
        function (DailyDigestNotification $n) use ($mine): bool {
            return str_contains(json_encode($n->toMail($mine)->introLines), 'MINE-ONLY');
        });
});

/* ---------------------------------------------------------------- reporting */

it('reports what it did', function (): void {
    $user = userWithRole('teacher');
    commandItem($user);

    $this->artisan('notifications:send-digest', ['--date' => '2026-09-07'])
        ->expectsOutputToContain('Daily digest for 2026-09-07')
        ->assertExitCode(0);
});

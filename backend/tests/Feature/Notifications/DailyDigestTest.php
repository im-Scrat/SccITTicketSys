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
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * WP-2.7e — **the daily digest** (SRS FR-NOT-008; SDD DD-63).
 *
 * A digest summarises rows that already exist. It writes no notification, so
 * DD-60's `dedupe_key` cannot protect it and `notification_digests` has to —
 * which is why so much of this file is about a table that carries no content.
 *
 * The three things most worth proving are all negatives: a user is never told
 * twice, never told about somebody else's work, and never sent a notification's
 * `message` body.
 */
beforeEach(function (): void {
    seedRbac();

    $this->digest = app(DailyDigest::class);
    $this->gate = app(NotificationPreferences::class);

    // A fixed instant so every boundary assertion is arithmetic, not luck.
    // 07:00 on the 8th in Manila is 23:00 on the 7th in UTC.
    $this->runAt = CarbonImmutable::parse('2026-09-08 07:00:00', DailyDigest::TIMEZONE);
    $this->window = $this->digest->windowFor($this->runAt);
});

/** A notification for `$user`, created at a school-local instant. */
function digestItem(User $user, string $localTime, array $attributes = []): Notification
{
    return Notification::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::Assignment->value,
        'title' => 'A ticket was assigned to you',
        'created_at' => CarbonImmutable::parse($localTime, DailyDigest::TIMEZONE),
        ...$attributes,
    ]);
}

/* ------------------------------------------------------------ the window */

it('summarises the previous school-local calendar day', function (): void {
    expect($this->window->date)->toBe('2026-09-07');
});

it('opens the window at midnight Manila and closes it at the next midnight', function (): void {
    // Manila is UTC+8, so the 7th runs 16:00Z on the 6th to 16:00Z on the 7th.
    expect($this->window->start->utc()->toIso8601String())->toBe('2026-09-06T16:00:00+00:00')
        ->and($this->window->end->utc()->toIso8601String())->toBe('2026-09-07T16:00:00+00:00');
});

it('closes the day well before the digest is sent', function (): void {
    expect($this->window->end->lessThan($this->runAt))->toBeTrue();
});

it('uses the school timezone the Client declared in system settings', function (): void {
    // The schedule and the window read a constant, not this row -- console.php
    // is loaded on every artisan call and must not query. This is the guard
    // that stops the two drifting apart silently, so it reads the Client's
    // actual declaration by running the seeder that carries it.
    $this->seed(Database\Seeders\SystemSettingSeeder::class);

    $declared = SystemSetting::query()->where('key', 'system.timezone')->value('value');

    expect(trim((string) $declared, "\"'"))->toBe(DailyDigest::TIMEZONE);
});

/* ------------------------------------------------------- window boundaries */

it('includes an item created at the first instant of the day', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-07 00:00:00');

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(1);
});

it('excludes an item created one second before the day opens', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-06 23:59:59');

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(0);
});

it('excludes an item created at the closing instant, which belongs to the next day', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-08 00:00:00');

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(0);
});

it('includes an item created at the last second of the day', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-07 23:59:59');

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(1);
});

/* ---------------------------------------------------------- what qualifies */

it('carries only unread items', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-07 09:00:00');
    digestItem($user, '2026-09-07 10:00:00', ['read_at' => CarbonImmutable::now()]);

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(1);
});

it('never carries another user\'s notifications', function (): void {
    $mine = userWithRole('teacher');
    $theirs = userWithRole('technician');

    digestItem($mine, '2026-09-07 09:00:00');
    digestItem($theirs, '2026-09-07 09:00:00');

    $items = $this->digest->itemsFor($mine, $this->window);

    expect($items)->toHaveCount(1)
        ->and($items->first()->user_id)->toBe($mine->getKey());
});

it('honours the per-type digest preference', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-07 09:00:00', ['type' => NotificationType::Assignment->value]);
    digestItem($user, '2026-09-07 10:00:00', ['type' => NotificationType::System->value]);

    $this->gate->set($user, NotificationChannel::Digest, NotificationType::Assignment, false);

    $items = $this->digest->itemsFor($user, $this->window);

    expect($items)->toHaveCount(1)
        ->and($items->first()->type)->toBe(NotificationType::System);
});

it('carries nothing when every type is switched off for the digest', function (): void {
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-07 09:00:00');

    foreach (NotificationType::cases() as $type) {
        $this->gate->set($user, NotificationChannel::Digest, $type, false);
    }

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(0);
});

it('is not disabled by switching the email channel off', function (): void {
    // The digest is its own channel (Client decision C). A user who wants no
    // per-event mail may still want the morning summary.
    $user = userWithRole('teacher');
    digestItem($user, '2026-09-07 09:00:00');

    foreach (NotificationType::cases() as $type) {
        $this->gate->set($user, NotificationChannel::Email, $type, false);
    }

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(1);
});

/* --------------------------------------------------------------- recipients */

it('offers the digest to all three roles', function (string $role): void {
    $user = userWithRole($role);
    digestItem($user, '2026-09-07 09:00:00');

    expect($this->digest->itemsFor($user, $this->window))->toHaveCount(1);
})->with(['administrator', 'technician', 'teacher']);

it('leaves out accounts that are not active', function (): void {
    $suspended = userWithRole('teacher', ['status' => UserStatus::Suspended->value]);

    expect($this->digest->candidates()->pluck('id'))->not->toContain($suspended->getKey());
});

/* -------------------------------------------------------------- idempotency */

it('claims a day for a user exactly once', function (): void {
    $user = userWithRole('teacher');

    expect($this->digest->claim($user, $this->window))->toBeTrue()
        ->and($this->digest->claim($user, $this->window))->toBeFalse();
});

it('records the claim against the day summarised, not the day it was sent', function (): void {
    $user = userWithRole('teacher');
    $this->digest->claim($user, $this->window);

    $row = NotificationDigest::query()->sole();

    expect($row->digest_date->toDateString())->toBe('2026-09-07')
        ->and($row->user_id)->toBe($user->getKey());
});

it('stamps sent_at when it commits, because the record is written before the mail', function (): void {
    $user = userWithRole('teacher');
    $this->digest->claim($user, $this->window);

    expect(NotificationDigest::query()->sole()->sent_at)->not->toBeNull();
});

it('lets two users hold the same day', function (): void {
    $a = userWithRole('teacher');
    $b = userWithRole('technician');

    expect($this->digest->claim($a, $this->window))->toBeTrue()
        ->and($this->digest->claim($b, $this->window))->toBeTrue();
});

it('lets one user hold two different days', function (): void {
    $user = userWithRole('teacher');
    $earlier = $this->digest->windowFor($this->runAt->subDay());

    expect($this->digest->claim($user, $this->window))->toBeTrue()
        ->and($this->digest->claim($user, $earlier))->toBeTrue();
});

it('refuses a duplicate at the database, by constraint name', function (): void {
    $user = userWithRole('teacher');
    $this->digest->claim($user, $this->window);

    expect(fn () => NotificationDigest::query()->create([
        'user_id' => $user->getKey(),
        'digest_date' => $this->window->date,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class, 'notification_digests_unique');
});

/* --------------------------------------------------------------- the email */

it('addresses the digest to the recipient and names the day', function (): void {
    NotificationFacade::fake();

    $user = userWithRole('teacher');
    $items = collect([digestItem($user, '2026-09-07 09:00:00')]);

    $user->notify(new DailyDigestNotification($items, $this->window));

    NotificationFacade::assertSentTo($user, DailyDigestNotification::class,
        function (DailyDigestNotification $n) use ($user): bool {
            $mail = $n->toMail($user);

            return $n->via($user) === ['mail']
                && str_contains((string) $mail->subject, '7 September 2026');
        });
});

it('carries notification titles and never their message bodies', function (): void {
    $user = userWithRole('teacher');

    $items = collect([digestItem($user, '2026-09-07 09:00:00', [
        'title' => 'A ticket was assigned to you',
        'message' => 'SECRET-COMMENT-BODY that must never be mailed',
    ])]);

    $mail = (new DailyDigestNotification($items, $this->window))->toMail($user);
    $rendered = json_encode([$mail->subject, $mail->introLines, $mail->outroLines]);

    expect($rendered)->toContain('A ticket was assigned to you')
        ->and($rendered)->not->toContain('SECRET-COMMENT-BODY');
});

it('sends through the mail channel only', function (): void {
    $user = userWithRole('teacher');
    $items = collect([digestItem($user, '2026-09-07 09:00:00')]);

    expect((new DailyDigestNotification($items, $this->window))->via($user))->toBe(['mail']);
});

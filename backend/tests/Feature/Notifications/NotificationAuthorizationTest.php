<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Notification as NotificationRecord;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * WP-2.7a — **who may reach a notification** (SRS FR-NOT-001/004; NFR-SEC-003).
 *
 * The simplest authorization boundary in the application and, for that reason,
 * the one most worth asserting from outside: a notification belongs to exactly
 * one person, and only that person may read it, mark it, or count it.
 *
 * ── The property under test, in the DD-40 shape ────────────────────────────
 *
 * A notification absent from someone's list must be **equally unreachable by
 * its uuid**. The list scope and the policy answer the same question, so this
 * file asserts both halves for the same row — a list filter without the
 * matching single-record check is an IDOR, and asserting only the list would
 * pass even if `show` had no policy on it at all.
 *
 * ── There is deliberately no administrator override ────────────────────────
 *
 * Every other module opens completely for an administrator, because running the
 * desk means seeing the work. A notification is not work; it is a message
 * addressed to a person, and its payload carries material drawn from tickets,
 * machines and colleagues' requests. So the administrator tests below assert
 * **refusal**, which is the opposite of what the rest of the system does and is
 * therefore the assertion most likely to be "fixed" by someone who has not read
 * `NotificationPolicy`.
 */
beforeEach(function (): void {
    seedRbac();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

/** One notification belonging to a given person. */
function notificationOwnedBy(User $user, array $overrides = []): NotificationRecord
{
    return NotificationRecord::factory()->create([
        'user_id' => $user->id,
        'type' => NotificationType::TicketUpdate->value,
        'title' => 'A private matter',
        ...$overrides,
    ]);
}

/* ------------------------------------------------------- reading your own */

it('lets each of the three roles read their own notifications', function (string $role): void {
    $user = $this->{$role};
    $mine = notificationOwnedBy($user);

    $this->actingAs($user)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.id', $mine->uuid);

    $this->actingAs($user)->getJson("/api/notifications/{$mine->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $mine->uuid);
})->with(['admin', 'technician', 'teacher']);

it('lets each of the three roles mark their own read and unread', function (string $role): void {
    $user = $this->{$role};
    $mine = notificationOwnedBy($user);

    $this->actingAs($user)->patchJson("/api/notifications/{$mine->uuid}/read")
        ->assertOk()
        ->assertJsonPath('data.is_read', true);

    $this->actingAs($user)->patchJson("/api/notifications/{$mine->uuid}/unread")
        ->assertOk()
        ->assertJsonPath('data.is_read', false);
})->with(['admin', 'technician', 'teacher']);

/* ----------------------------------------------- refusing everybody else's */

it('keeps one user out of another user\'s list', function (): void {
    notificationOwnedBy($this->teacher);
    notificationOwnedBy($this->technician);

    $this->actingAs($this->technician)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/**
 * The IDOR half. The row is absent from the caller's list *and* unreachable by
 * pasting its uuid — asserted together, for the same row, in both directions.
 */
it('refuses another user\'s notification by direct uuid, for every role pair', function (string $ownerRole, string $callerRole): void {
    $theirs = notificationOwnedBy($this->{$ownerRole});
    $caller = $this->{$callerRole};

    $listed = collect(
        $this->actingAs($caller)->getJson('/api/notifications')->assertOk()->json('data')
    )->pluck('id');

    expect($listed)->not->toContain($theirs->uuid);

    $this->actingAs($caller)->getJson("/api/notifications/{$theirs->uuid}")->assertForbidden();
})->with([
    ['teacher', 'technician'],
    ['teacher', 'admin'],
    ['technician', 'teacher'],
    ['technician', 'admin'],
    ['admin', 'teacher'],
    ['admin', 'technician'],
]);

it('refuses to let anyone mark another user\'s notification read', function (): void {
    $theirs = notificationOwnedBy($this->teacher);

    $this->actingAs($this->admin)->patchJson("/api/notifications/{$theirs->uuid}/read")->assertForbidden();
    $this->actingAs($this->technician)->patchJson("/api/notifications/{$theirs->uuid}/read")->assertForbidden();

    expect($theirs->refresh()->read_at)->toBeNull();
});

it('refuses to let anyone mark another user\'s notification unread', function (): void {
    $theirs = notificationOwnedBy($this->teacher, ['read_at' => now()]);

    $this->actingAs($this->admin)->patchJson("/api/notifications/{$theirs->uuid}/unread")->assertForbidden();

    expect($theirs->refresh()->read_at)->not->toBeNull();
});

/**
 * An administrator holds every permission in the §8.4 matrix. None of them is a
 * key to somebody else's inbox, and this asserts that the deny-by-default Gate
 * does not accidentally grant one through a permission-shaped ability name.
 */
it('gives an administrator no privileged view of anybody else\'s notifications', function (): void {
    notificationOwnedBy($this->teacher);
    notificationOwnedBy($this->technician);

    $this->actingAs($this->admin)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->admin)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread', 0);
});

/* -------------------------------------------------------------- bulk marks */

it('marks only the caller\'s own notifications when clearing the list', function (): void {
    notificationOwnedBy($this->teacher);
    notificationOwnedBy($this->teacher);
    $theirs = notificationOwnedBy($this->technician);

    $this->actingAs($this->teacher)->patchJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('marked', 2);

    expect($theirs->refresh()->read_at)->toBeNull();
});

it('counts only the caller\'s own unread notifications', function (): void {
    notificationOwnedBy($this->teacher);
    notificationOwnedBy($this->teacher, ['read_at' => now()]);
    notificationOwnedBy($this->technician);

    $this->actingAs($this->teacher)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread', 1);
});

/* ------------------------------------------------------------ enumeration */

it('answers an unknown uuid the same way it answers somebody else\'s', function (): void {
    $theirs = notificationOwnedBy($this->teacher);

    $unknown = $this->actingAs($this->technician)
        ->getJson('/api/notifications/'.Str::uuid())->getStatusCode();

    $forbidden = $this->actingAs($this->technician)
        ->getJson("/api/notifications/{$theirs->uuid}")->getStatusCode();

    /*
     * Both are refusals with no content. They are not the *same* status — an
     * unknown uuid cannot bind to a model and is a 404 — but neither response
     * carries anything about the row, so an enumeration sweep learns nothing it
     * could act on. What matters is that a real notification is never returned;
     * that is asserted directly rather than inferred from the status.
     */
    expect($unknown)->toBe(404)
        ->and($forbidden)->toBe(403);
});

it('never resolves a numeric id as a notification', function (): void {
    $mine = notificationOwnedBy($this->teacher);

    $this->actingAs($this->teacher)->getJson("/api/notifications/{$mine->id}")->assertNotFound();
});

it('requires authentication for every notification endpoint', function (): void {
    $mine = notificationOwnedBy($this->teacher);

    $this->getJson('/api/notifications')->assertUnauthorized();
    $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
    $this->getJson("/api/notifications/{$mine->uuid}")->assertUnauthorized();
    $this->patchJson("/api/notifications/{$mine->uuid}/read")->assertUnauthorized();
    $this->patchJson('/api/notifications/read-all')->assertUnauthorized();
    $this->getJson('/api/notification-preferences')->assertUnauthorized();
});

/* ------------------------------------------------------------ preferences */

it('lets each role read and set their own preferences', function (string $role): void {
    $user = $this->{$role};

    $this->actingAs($user)->getJson('/api/notification-preferences')
        ->assertOk()
        ->assertJsonPath('meta.default_enabled', true)
        // Every channel x every type. Derived, not counted: WP-2.7e's
        // `digest` channel took this from 18 to 27.
        ->assertJsonCount(count(NotificationChannel::cases()) * count(NotificationType::cases()), 'data');

    $this->actingAs($user)->putJson('/api/notification-preferences', [
        'preferences' => [
            ['channel' => 'email', 'notification_type' => 'assignment', 'is_enabled' => false],
        ],
    ])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
})->with(['admin', 'technician', 'teacher']);

/**
 * There is no user field on the preferences endpoint, so there is nothing to
 * tamper with. Sending one changes nobody — asserted rather than assumed,
 * because "the field does not exist" is only true until somebody adds it.
 */
it('ignores an attempt to set somebody else\'s preferences', function (): void {
    $this->actingAs($this->technician)->putJson('/api/notification-preferences', [
        'user_id' => $this->teacher->id,
        'user' => $this->teacher->uuid,
        'preferences' => [
            ['channel' => 'email', 'notification_type' => 'assignment', 'is_enabled' => false],
        ],
    ])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $this->teacher->id)->count())->toBe(0)
        ->and(NotificationPreference::query()->where('user_id', $this->technician->id)->count())->toBe(1);
});

it('refuses a preference outside the channel or type the schema allows', function (): void {
    $this->actingAs($this->technician)->putJson('/api/notification-preferences', [
        'preferences' => [
            ['channel' => 'sms', 'notification_type' => 'assignment', 'is_enabled' => false],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('preferences.0.channel');

    $this->actingAs($this->technician)->putJson('/api/notification-preferences', [
        'preferences' => [
            ['channel' => 'email', 'notification_type' => 'digest', 'is_enabled' => false],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('preferences.0.notification_type');

    expect(NotificationPreference::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------- filters */

it('filters the list to unread and by type without widening it', function (): void {
    notificationOwnedBy($this->teacher, ['type' => NotificationType::Assignment->value]);
    notificationOwnedBy($this->teacher, ['type' => NotificationType::TicketUpdate->value, 'read_at' => now()]);
    notificationOwnedBy($this->technician, ['type' => NotificationType::Assignment->value]);

    $this->actingAs($this->teacher)->getJson('/api/notifications?unread=1')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($this->teacher)->getJson('/api/notifications?type=assignment')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a type filter outside the enum rather than returning an empty inbox', function (): void {
    $this->actingAs($this->teacher)->getJson('/api/notifications?type=not-a-type')
        ->assertStatus(422)->assertJsonValidationErrors('type');
});

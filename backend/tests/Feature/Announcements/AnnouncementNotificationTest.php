<?php

declare(strict_types=1);

use App\Enums\AnnouncementAudience;
use App\Enums\NotificationTopic;
use App\Models\Announcement;
use App\Models\Notification as NotificationRecord;
use Illuminate\Support\Facades\Mail;

/**
 * WP-2.7c — **publishing notifies the audience, and only in-app**
 * (SRS FR-NOT-003 trigger 12, FR-NOT-010; D5 (SRS §22.1); decisions D7, D9).
 *
 * The single most important assertion in this work package is the negative one:
 * **publishing an announcement must not send email.** Before WP-2.7c-1 the
 * infrastructure could not express that — `via()` admitted any channel the
 * opt-out preference gate allowed, and since an absent preference row means
 * *enabled*, a first `all` announcement would have mailed the entire
 * organization. That is not a hypothetical failure mode; it was the default one.
 *
 * The rest of this file holds the recipient rules the Client settled:
 * the targeted audience is told, everyone else is not, the publishing
 * administrator is not told about their own announcement (D9), an ordinary edit
 * notifies nobody (D7), and a deliberate repeat does (D7 again).
 */
beforeEach(function (): void {
    seedRbac();

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician');

    Mail::fake();
});

/** Publish through the real HTTP path, so the whole chain is exercised. */
function publishAnnouncement(mixed $test, Announcement $announcement): void
{
    $test->actingAs($test->admin)
        ->postJson('/api/admin/announcements/'.$announcement->uuid.'/publish')
        ->assertOk();
}

/** In-app notifications written for one user about announcements. */
function announcementRowsFor(mixed $user): int
{
    return NotificationRecord::query()
        ->where('user_id', $user->getKey())
        ->where('type', 'announcement')
        ->count();
}

it('sends NO email when an announcement is published', function (): void {
    // The reason WP-2.7c-1 exists. Preferences are opt-out, so without the
    // channel exclusion every recipient below would be mailed.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    Mail::assertNothingSent();
});

it('writes an in-app notification for the targeted audience', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::Teachers,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    expect(announcementRowsFor($this->teacher))->toBe(1);
});

it('does not notify a role outside the audience', function (): void {
    // Audience targeting is an authorization boundary. A technician is not
    // shown a teachers-only announcement and is not told about it either.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::Teachers,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    expect(announcementRowsFor($this->technician))->toBe(0);
});

it('does not notify the publishing administrator about their own announcement', function (): void {
    // decision D9, and the dispatcher's existing actor-exclusion rule. The
    // audience is `all`, so the administrator is genuinely in it — and is still
    // not told about the thing they just wrote.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    expect(announcementRowsFor($this->admin))->toBe(0)
        ->and(announcementRowsFor($this->teacher))->toBe(1);
});

it('carries an internal destination the client is allowed to follow', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    $row = NotificationRecord::query()
        ->where('user_id', $this->teacher->getKey())
        ->sole();

    expect($row->action_url)->toBe('/app/announcements/'.$announcement->uuid)
        ->and($row->action_url)->toStartWith('/app/');
});

it('files the notification under the announcement type and topic', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    $row = NotificationRecord::query()->where('user_id', $this->teacher->getKey())->sole();

    expect($row->type->value)->toBe('announcement')
        ->and($row->data['topic'] ?? null)->toBe(NotificationTopic::AnnouncementPublished->value);
});

it('does not carry the announcement body into the notification', function (): void {
    // DD-61: structural facts only. The title names it; the body is read on the
    // announcement page, not exported into a notification row.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
        'title' => 'Server room access',
        'content' => 'The keypad code changes to 4417 on Monday.',
    ]);

    publishAnnouncement($this, $announcement);

    $row = NotificationRecord::query()->where('user_id', $this->teacher->getKey())->sole();

    // The whole stored row is searched, not just one field: the guarantee is
    // that the body does not reach the notification by *any* route — title,
    // message or the free-form `data` bag.
    expect($row->title)->toBe('Server room access')
        ->and($row->message)->toBeNull()
        ->and(json_encode([$row->title, $row->message, $row->data]))->not->toContain('4417');
});

it('does not duplicate notifications when an announcement is published twice', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);
    publishAnnouncement($this, $announcement);

    expect(announcementRowsFor($this->teacher))->toBe(1);
});

it('notifies nobody when an announcement is merely edited', function (): void {
    // decision D7 — the protection against a surprise blast.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);
    expect(announcementRowsFor($this->teacher))->toBe(1);

    $this->actingAs($this->admin)
        ->putJson('/api/admin/announcements/'.$announcement->uuid, [
            'title' => 'Server room access (corrected)',
            'content' => 'Updated wording.',
            'audience' => AnnouncementAudience::All->value,
        ])
        ->assertOk();

    // Still one: the edit told nobody.
    expect(announcementRowsFor($this->teacher))->toBe(1);
});

it('notifies again when an administrator explicitly asks it to', function (): void {
    // The other half of D7: a repeat is possible, but only on purpose.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/announcements/'.$announcement->uuid.'/notify')
        ->assertOk();

    expect(announcementRowsFor($this->teacher))->toBe(2);
});

it('sends no email on a deliberate re-notification either', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    publishAnnouncement($this, $announcement);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/announcements/'.$announcement->uuid.'/notify')
        ->assertOk();

    Mail::assertNothingSent();
});

it('notifies nobody when an announcement is withdrawn', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/announcements/'.$announcement->uuid.'/unpublish')
        ->assertOk();

    expect(announcementRowsFor($this->teacher))->toBe(0);
});

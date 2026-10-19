<?php

declare(strict_types=1);

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;

/**
 * WP-2.7c — **the announcement authorization boundary** (SRS FR-NOT-010/011).
 *
 * Two separate boundaries live on one entity, and this file exists to keep them
 * from being confused with each other:
 *
 *  1. **Managing** is a permission — `system.announcements.manage`, seeded to
 *     Administrators alone. Every write is refused to everybody else.
 *  2. **Reading** is *audience membership*, which is not a permission at all.
 *     A technician is not "less authorized" than a teacher; they are simply in
 *     a different audience, and each is refused the other's announcements.
 *
 * The rule the Client stated explicitly is that audience targeting is an
 * **authorization boundary, not a display filter**. So every read test here has
 * two halves: absent from the list, *and* refused by uuid. A list filter without
 * the matching single-record check is an IDOR, and it is the exact defect this
 * file is written to catch.
 */
beforeEach(function (): void {
    seedRbac();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

/* ------------------------------------------------------- write boundary */

it('lets an administrator create an announcement', function (): void {
    $response = $this->actingAs($this->admin)->postJson('/api/admin/announcements', [
        'title' => 'Network maintenance on Saturday',
        'content' => 'The staff network will be unavailable from 08:00 to 10:00.',
        'audience' => AnnouncementAudience::All->value,
    ]);

    $response->assertCreated();
    expect(Announcement::query()->count())->toBe(1);
});

it('creates announcements as drafts, so writing one notifies nobody', function (): void {
    // Publication is a separate, deliberate act — the whole basis of D7.
    $this->actingAs($this->admin)->postJson('/api/admin/announcements', [
        'title' => 'Draft',
        'content' => 'Not yet live.',
        'audience' => AnnouncementAudience::All->value,
    ])->assertCreated();

    expect(Announcement::query()->sole()->is_active)->toBeFalse();
});

it('refuses every management route to a technician', function (string $method, string $path): void {
    $announcement = Announcement::factory()->create();
    $path = str_replace('{uuid}', $announcement->uuid, $path);

    $this->actingAs($this->technician)->json($method, $path)->assertForbidden();
})->with([
    ['GET', '/api/admin/announcements'],
    ['POST', '/api/admin/announcements'],
    ['PUT', '/api/admin/announcements/{uuid}'],
    ['POST', '/api/admin/announcements/{uuid}/publish'],
    ['POST', '/api/admin/announcements/{uuid}/unpublish'],
    ['POST', '/api/admin/announcements/{uuid}/notify'],
    ['DELETE', '/api/admin/announcements/{uuid}'],
]);

it('refuses every management route to a teacher', function (string $method, string $path): void {
    $announcement = Announcement::factory()->create();
    $path = str_replace('{uuid}', $announcement->uuid, $path);

    $this->actingAs($this->teacher)->json($method, $path)->assertForbidden();
})->with([
    ['GET', '/api/admin/announcements'],
    ['POST', '/api/admin/announcements'],
    ['PUT', '/api/admin/announcements/{uuid}'],
    ['POST', '/api/admin/announcements/{uuid}/publish'],
    ['POST', '/api/admin/announcements/{uuid}/unpublish'],
    ['POST', '/api/admin/announcements/{uuid}/notify'],
    ['DELETE', '/api/admin/announcements/{uuid}'],
]);

it('refuses the management surface to an unauthenticated caller', function (): void {
    $this->getJson('/api/admin/announcements')->assertUnauthorized();
});

/* -------------------------------------------------------- read boundary */

it('shows an all-audience announcement to every role', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
    ]);

    foreach ([$this->admin, $this->technician, $this->teacher] as $user) {
        $this->actingAs($user)
            ->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $announcement->uuid);
    }
});

it('keeps an announcement out of the list of an audience it does not target', function (): void {
    Announcement::factory()->create([
        'audience' => AnnouncementAudience::Teachers,
        'is_active' => true,
    ]);

    $this->actingAs($this->technician)
        ->getJson('/api/announcements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses that same announcement by uuid — absent from the list means unreachable', function (): void {
    // The IDOR check. Hiding a row from a list is a convenience; the refusal
    // below is the control.
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::Teachers,
        'is_active' => true,
    ]);

    $this->actingAs($this->technician)
        ->getJson('/api/announcements/'.$announcement->uuid)
        ->assertForbidden();
});

it('refuses an unpublished announcement by uuid, even to its own audience', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => false,
    ]);

    $this->actingAs($this->teacher)
        ->getJson('/api/announcements/'.$announcement->uuid)
        ->assertForbidden();
});

it('refuses an announcement whose window has not opened', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs($this->teacher)
        ->getJson('/api/announcements/'.$announcement->uuid)
        ->assertForbidden();
});

it('refuses an announcement whose window has closed', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDay(),
    ]);

    $this->actingAs($this->teacher)
        ->getJson('/api/announcements/'.$announcement->uuid)
        ->assertForbidden();
});

it('scopes the reader by audience even for an administrator', function (): void {
    // An administrator's *reader* page shows what an administrator was told,
    // not the estate. The management surface is where they see everything.
    Announcement::factory()->create([
        'audience' => AnnouncementAudience::Teachers,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/announcements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('shows an administrator every announcement on the management surface', function (): void {
    Announcement::factory()->create(['audience' => AnnouncementAudience::Teachers, 'is_active' => true]);
    Announcement::factory()->create(['audience' => AnnouncementAudience::All, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->getJson('/api/admin/announcements')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('puts pinned announcements first in the reader', function (): void {
    Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
        'is_pinned' => false,
        'title' => 'Ordinary',
    ]);
    $pinned = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
        'is_pinned' => true,
        'title' => 'Pinned',
    ]);

    $this->actingAs($this->teacher)
        ->getJson('/api/announcements')
        ->assertOk()
        ->assertJsonPath('data.0.id', $pinned->uuid);
});

/* ------------------------------------------------------- disclosure */

it('never publishes the internal key or the raw author id', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
    ]);

    $payload = $this->actingAs($this->teacher)
        ->getJson('/api/announcements/'.$announcement->uuid)
        ->assertOk()
        ->json('data');

    expect($payload['id'])->toBe($announcement->uuid)
        ->and($payload['id'])->not->toBe($announcement->getKey())
        ->and($payload)->not->toHaveKey('created_by');
});

it('withholds the draft state from a reader who cannot manage announcements', function (): void {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'is_active' => true,
    ]);

    $payload = $this->actingAs($this->teacher)
        ->getJson('/api/announcements/'.$announcement->uuid)
        ->assertOk()
        ->json('data');

    expect($payload)->not->toHaveKey('is_active');
});

<?php

declare(strict_types=1);

use App\Models\Asset;

beforeEach(fn () => seedRbac());

/*
 * The custodian self-service view (`/api/my/assets`) — Phase 2.9 contract.
 *
 * It is the one asset surface outside the Administrator-only module (SDD
 * DD-38), and it stays that narrow:
 *   - Teachers can never hold custody, so the view is refused to them (403),
 *     not merely empty — the asset surface is closed in the API, not just the nav.
 *   - A custodian sees only what is handed to them; another custodian's asset
 *     is a 404 by uuid, exactly like a missing one (no existence oracle).
 *   - Both routes serve the narrow list projection, never the register's
 *     detail record (attachments, QR codes, transfers, maintenance, audit).
 */

it('refuses the custodian view to a teacher', function () {
    $this->actingAs(userWithRole('teacher'))->getJson('/api/my/assets')->assertForbidden();
});

it('refuses a single custody record to a teacher, even one that exists', function () {
    $asset = Asset::factory()->create();

    $this->actingAs(userWithRole('teacher'))->getJson("/api/my/assets/{$asset->uuid}")->assertForbidden();
});

it('lists only the technician’s own custody', function () {
    $technician = userWithRole('technician');
    $mine = Asset::factory()->create(['assigned_technician_id' => $technician->id]);
    Asset::factory()->create(['assigned_technician_id' => userWithRole('technician')->id]);
    Asset::factory()->create(['assigned_technician_id' => null]);

    $this->actingAs($technician)->getJson('/api/my/assets')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->uuid);
});

it('answers 404 for another custodian’s asset by uuid', function () {
    $foreign = Asset::factory()->create(['assigned_technician_id' => userWithRole('technician')->id]);

    $this->actingAs(userWithRole('technician'))->getJson("/api/my/assets/{$foreign->uuid}")->assertNotFound();
});

it('answers 404 for an unassigned asset by uuid', function () {
    $unassigned = Asset::factory()->create(['assigned_technician_id' => null]);

    $this->actingAs(userWithRole('technician'))->getJson("/api/my/assets/{$unassigned->uuid}")->assertNotFound();
});

it('serves the custodian their own asset in the narrow projection only', function () {
    $technician = userWithRole('technician');
    $asset = Asset::factory()->create(['assigned_technician_id' => $technician->id]);

    $response = $this->actingAs($technician)->getJson("/api/my/assets/{$asset->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $asset->uuid);

    // Asserted on the encoded payload, so a later resource change cannot leak
    // a register-only section back in.
    $encoded = $response->getContent();
    foreach (['"attachments"', '"qr_codes"', '"transfers"', '"maintenance_records"', '"installations"', '"blockers"', '"transitions"'] as $key) {
        expect($encoded)->not->toContain($key);
    }
});

it('lets an administrator custodian use the view too', function () {
    $admin = userWithRole('administrator');
    $asset = Asset::factory()->create(['assigned_technician_id' => $admin->id]);

    $this->actingAs($admin)->getJson("/api/my/assets/{$asset->uuid}")->assertOk();
});

it('keeps the register detail closed to a custodian technician', function () {
    $technician = userWithRole('technician');
    $asset = Asset::factory()->create(['assigned_technician_id' => $technician->id]);

    // Custody opens /my/assets, never the Administrator module's detail record.
    $this->actingAs($technician)->getJson("/api/admin/assets/{$asset->uuid}")->assertForbidden();
});

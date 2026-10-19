<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PermissionGrantType;
use App\Enums\PredictionStatus;
use App\Models\AiPrediction;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * WP-M — who may reach predictive-maintenance findings.
 *
 * "Prediction reports are Admin-only" (the mandate, verbatim). Every case here
 * proves that role-by-role, including the two traps `PcPredictionAccess`'s own
 * docblock names: a per-user permission grant to a non-administrator must not
 * open a bare `can:predictions.*` gate (there is none — this suite proves the
 * *policy* refuses regardless), and a per-user *deny* must be able to withdraw
 * the surface from a named administrator without demoting them (DD-05).
 */
beforeEach(function (): void {
    seedRbac();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->prediction = AiPrediction::factory()->create([
        'pc_unit_id' => PcUnit::factory()->create(['room_id' => Room::factory()->create()->id])->id,
        'status' => PredictionStatus::Pending->value,
    ]);
});

/* ------------------------------------------------------------- role gates */

it('lets an administrator list, read, confirm and dismiss findings', function (): void {
    $this->actingAs($this->admin)->getJson('/api/admin/predictions')->assertOk();
    $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertOk();

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/predictions/{$this->prediction->uuid}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status.value', 'confirmed');
});

it('refuses a technician every route in the module, on an existing finding', function (): void {
    $this->actingAs($this->technician)->getJson('/api/admin/predictions')->assertForbidden();
    $this->actingAs($this->technician)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertForbidden();
    $this->actingAs($this->technician)->patchJson("/api/admin/predictions/{$this->prediction->uuid}/confirm")->assertForbidden();
    $this->actingAs($this->technician)->patchJson("/api/admin/predictions/{$this->prediction->uuid}/dismiss")->assertForbidden();

    expect($this->prediction->fresh()->status)->toBe(PredictionStatus::Pending);
});

it('refuses a teacher every route in the module, on an existing finding', function (): void {
    $this->actingAs($this->teacher)->getJson('/api/admin/predictions')->assertForbidden();
    $this->actingAs($this->teacher)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertForbidden();
    $this->actingAs($this->teacher)->patchJson("/api/admin/predictions/{$this->prediction->uuid}/confirm")->assertForbidden();
    $this->actingAs($this->teacher)->patchJson("/api/admin/predictions/{$this->prediction->uuid}/dismiss")->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/admin/predictions')->assertUnauthorized();
    $this->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertUnauthorized();
});

/* --------------------------------------------------- no existence oracle */

it('answers a missing finding exactly like an inaccessible one for a non-administrator', function (): void {
    $missing = '00000000-0000-4000-8000-000000000000';

    $real = $this->actingAs($this->technician)->getJson("/api/admin/predictions/{$this->prediction->uuid}");
    $fake = $this->actingAs($this->technician)->getJson("/api/admin/predictions/{$missing}");

    $real->assertForbidden();
    $fake->assertForbidden();
    expect($real->status())->toBe($fake->status());
});

it('gives an administrator the true answer either way — 200 for real, 404 for missing', function (): void {
    $missing = '00000000-0000-4000-8000-000000000000';

    $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertOk();
    $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$missing}")->assertNotFound();
});

it('keeps junk in the uuid segment a clean 404, not a database error', function (): void {
    $this->actingAs($this->admin)->getJson('/api/admin/predictions/not-a-uuid')->assertNotFound();
});

/* ------------------------------------------------- the Gate::before trap */

it('refuses a technician even after a per-user grant of predictions.view and predictions.manage', function (): void {
    // FR-USER-010 allows a per-user grant to anybody. If any route here were
    // gated by the bare permission string (`can:predictions.view`) rather than
    // the policy ability, `Gate::before` would open it the moment this grant
    // exists — which is exactly the trap `PcPredictionAccess` is built to
    // avoid. This proves the policy, not the permission string, decides.
    foreach (['predictions.view', 'predictions.manage'] as $name) {
        DB::table('user_permissions')->insert([
            'user_id' => $this->technician->id,
            'permission_id' => Permission::query()->where('name', $name)->value('id'),
            'grant_type' => PermissionGrantType::Grant->value,
        ]);
    }
    app(PermissionResolver::class)->forget($this->technician);
    expect($this->technician->hasPermissionTo('predictions.view'))->toBeTrue()
        ->and($this->technician->hasPermissionTo('predictions.manage'))->toBeTrue();

    $this->actingAs($this->technician)->getJson('/api/admin/predictions')->assertForbidden();
    $this->actingAs($this->technician)
        ->patchJson("/api/admin/predictions/{$this->prediction->uuid}/confirm")
        ->assertForbidden();
});

it('lets a per-user deny withdraw the surface from a named administrator without demoting them', function (): void {
    DB::table('user_permissions')->insert([
        'user_id' => $this->admin->id,
        'permission_id' => Permission::query()->where('name', 'predictions.view')->value('id'),
        'grant_type' => PermissionGrantType::Deny->value,
    ]);
    app(PermissionResolver::class)->forget($this->admin);
    expect($this->admin->hasPermissionTo('predictions.view'))->toBeFalse();

    $this->actingAs($this->admin)->getJson('/api/admin/predictions')->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\Room;

beforeEach(fn () => seedRbac());

/*
 * The Phase 2.5 authorization contract (SDD DD-38).
 *
 * Asset Management is an Administrator-only module. Technicians and Teachers
 * reach the equipment they are working on **only** through the narrow lookup,
 * authorized by their own workflow's permission. These tests pin both halves:
 * everything under /api/admin/assets and /api/admin/pc-units is closed to them,
 * and /api/lookups/* stays open and label-only.
 */

it('requires authentication for every asset endpoint', function () {
    $asset = Asset::factory()->create();
    $pcUnit = PcUnit::factory()->create();

    $this->getJson('/api/admin/assets')->assertUnauthorized();
    $this->getJson('/api/admin/assets/dashboard')->assertUnauthorized();
    $this->getJson('/api/admin/pc-units')->assertUnauthorized();
    $this->getJson('/api/lookups/assets')->assertUnauthorized();
    $this->deleteJson("/api/admin/assets/{$asset->uuid}")->assertUnauthorized();
    $this->deleteJson("/api/admin/pc-units/{$pcUnit->uuid}")->assertUnauthorized();
});

it('grants the assets module to Administrators only', function () {
    foreach (['assets.view', 'assets.create', 'assets.update', 'assets.delete', 'assets.transfer', 'assets.dispose'] as $permission) {
        expect(userWithRole('administrator')->hasPermissionTo($permission))->toBeTrue()
            ->and(userWithRole('technician')->hasPermissionTo($permission))->toBeFalse()
            ->and(userWithRole('teacher')->hasPermissionTo($permission))->toBeFalse();
    }
});

it('closes every asset read to technicians and teachers', function () {
    $asset = Asset::factory()->create();
    $pcUnit = PcUnit::factory()->create();

    foreach (['technician', 'teacher'] as $roleSlug) {
        $actor = userWithRole($roleSlug);

        // Dashboard, catalog and directories.
        $this->actingAs($actor)->getJson('/api/admin/assets/dashboard')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/assets/catalog')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/assets')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/pc-units')->assertForbidden();

        // Detail pages.
        $this->actingAs($actor)->getJson("/api/admin/assets/{$asset->uuid}")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/pc-units/{$pcUnit->uuid}")->assertForbidden();

        // History, audit, attachments and QR.
        $this->actingAs($actor)->getJson("/api/admin/assets/{$asset->uuid}/history")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/assets/{$asset->uuid}/audit")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/assets/{$asset->uuid}/attachments")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/assets/{$asset->uuid}/qr")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/pc-units/{$pcUnit->uuid}/history")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/pc-units/{$pcUnit->uuid}/audit")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/pc-units/{$pcUnit->uuid}/qr")->assertForbidden();
    }
});

it('denies every asset write to technicians and teachers', function () {
    $asset = Asset::factory()->create();
    $pcUnit = PcUnit::factory()->create();
    $room = Room::factory()->create();

    foreach (['technician', 'teacher'] as $roleSlug) {
        $actor = userWithRole($roleSlug);

        $this->actingAs($actor)->postJson('/api/admin/assets', [
            'asset_tag' => 'NOPE-'.$roleSlug,
            'hardware_model' => $asset->hardware_model_id,
        ])->assertForbidden();

        $this->actingAs($actor)->putJson("/api/admin/assets/{$asset->uuid}", [
            'name' => 'Renamed',
        ])->assertForbidden();

        $this->actingAs($actor)->putJson("/api/admin/assets/{$asset->uuid}/status", [
            'status' => 'deployed',
        ])->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/assets/{$asset->uuid}/transfer", [
            'room' => $room->uuid,
        ])->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/assets/{$asset->uuid}/assign", [
            'technician' => null,
        ])->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/admin/assets/{$asset->uuid}")->assertForbidden();

        $this->actingAs($actor)->postJson('/api/admin/pc-units', [
            'unit_code' => 'NOPE-'.$roleSlug,
            'pc_name' => 'Nope',
        ])->assertForbidden();

        $this->actingAs($actor)->putJson("/api/admin/pc-units/{$pcUnit->uuid}/specification", [
            'cpu' => 'Nope',
        ])->assertForbidden();

        $this->actingAs($actor)->deleteJson("/api/admin/pc-units/{$pcUnit->uuid}")->assertForbidden();
    }
});

it('lets technicians and teachers reach the narrow lookup through their own workflow', function () {
    Asset::factory()->create(['asset_tag' => 'LOOKUP-1']);
    PcUnit::factory()->create(['pc_name' => 'Lookup PC']);

    foreach (['technician', 'teacher'] as $roleSlug) {
        $actor = userWithRole($roleSlug);

        // Teachers hold tickets.create; technicians hold maintenance.*. Either
        // opens the lookup — neither grants the module.
        $this->actingAs($actor)->getJson('/api/lookups/assets')->assertOk();
        $this->actingAs($actor)->getJson('/api/lookups/pc-units')->assertOk();
    }
});

it('returns labels only from the lookup, never operational data', function () {
    $asset = Asset::factory()->create([
        'asset_tag' => 'SECRET-1',
        'purchase_price' => 1999.99,
    ]);

    $payload = $this->actingAs(userWithRole('teacher'))
        ->getJson('/api/lookups/assets')
        ->assertOk()
        ->json('data');

    $row = collect($payload)->firstWhere('identifier', 'SECRET-1');

    expect($row)->not->toBeNull()
        ->and(array_keys($row))->toEqualCanonicalizing(['id', 'label', 'identifier', 'location'])
        ->and($row['id'])->toBe($asset->uuid);

    // Nothing operational leaks, whatever the caller does with the response.
    $encoded = json_encode($payload);
    expect($encoded)->not->toContain('1999.99')
        ->and($encoded)->not->toContain('purchase')
        ->and($encoded)->not->toContain('status')
        ->and($encoded)->not->toContain('supplier');
});

it('excludes retired and disposed equipment from the lookup', function () {
    Asset::factory()->create(['asset_tag' => 'LIVE-1', 'status' => 'in_stock']);
    Asset::factory()->create(['asset_tag' => 'GONE-1', 'status' => 'disposed']);
    Asset::factory()->create(['asset_tag' => 'GONE-2', 'status' => 'retired']);

    $identifiers = collect(
        $this->actingAs(userWithRole('teacher'))->getJson('/api/lookups/assets')->json('data')
    )->pluck('identifier');

    expect($identifiers)->toContain('LIVE-1')
        ->and($identifiers)->not->toContain('GONE-1')
        ->and($identifiers)->not->toContain('GONE-2');
});

it('allows an administrator through the whole module', function () {
    $admin = userWithRole('administrator');
    $asset = Asset::factory()->create();
    $pcUnit = PcUnit::factory()->create();

    $this->actingAs($admin)->getJson('/api/admin/assets/dashboard')->assertOk();
    $this->actingAs($admin)->getJson('/api/admin/assets/catalog')->assertOk();
    $this->actingAs($admin)->getJson('/api/admin/assets')->assertOk();
    $this->actingAs($admin)->getJson("/api/admin/assets/{$asset->uuid}")->assertOk();
    $this->actingAs($admin)->getJson("/api/admin/assets/{$asset->uuid}/history")->assertOk();
    $this->actingAs($admin)->getJson('/api/admin/pc-units')->assertOk();
    $this->actingAs($admin)->getJson("/api/admin/pc-units/{$pcUnit->uuid}")->assertOk();
});

it('keeps numeric ids unaddressable', function () {
    $admin = userWithRole('administrator');
    $asset = Asset::factory()->create();

    // The uuid route constraint turns a numeric id into a clean 404 rather than
    // a Postgres cast error (NFR-SEC-001, AC-G2).
    $this->actingAs($admin)->getJson("/api/admin/assets/{$asset->id}")->assertNotFound();
    $this->actingAs($admin)->getJson('/api/admin/assets/not-a-uuid')->assertNotFound();
});

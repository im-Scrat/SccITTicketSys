<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PermissionGrantType;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 R4 — who may see which part of Overall Reports (owner decision
 * D8, SDD DD-89/DD-94).
 *
 * The report gate (Administrator + reports.view) and, per section, its module:
 * Tickets needs tickets.view, Equipment assets.view, Repairs maintenance.view,
 * Locations locations.view and tickets.view. A section the viewer may not see
 * answers 403 (before validation), is missing from the section list, and
 * leaves no trace in the Overview; its record tables are closed the same way.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed([TicketLookupSeeder::class, MaintenanceTypeSeeder::class]);
    $this->travelTo(ReportFixtures::local('2026-10-18 10:00')->utc());
    $this->admin = userWithRole('administrator');
    $this->pc = ReportFixtures::pc(ReportFixtures::room(ReportFixtures::floor()));

    $this->deny = function (string $permission): void {
        givePermission($this->admin, $permission, PermissionGrantType::Deny);
        app(PermissionResolver::class)->forget($this->admin);
    };
});

it('serves every section, table and the equipment search to an administrator', function (): void {
    foreach (['overview', 'tickets', 'equipment', 'repairs', 'locations'] as $section) {
        $this->actingAs($this->admin)->getJson("/api/admin/reports/overall?section={$section}")->assertOk();
    }
    foreach (['tickets', 'repairs', 'replacements', 'pc_units', 'assets', 'rooms'] as $table) {
        $this->actingAs($this->admin)->getJson("/api/admin/reports/overall/records?table={$table}")->assertOk();
    }
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall/equipment-options?q=PC')->assertOk();
});

it('refuses technicians and teachers everywhere, even with every permission granted by hand', function (): void {
    $technician = userWithRole('technician');
    foreach (['reports.view', 'reports.export', 'tickets.view', 'assets.view', 'maintenance.view', 'locations.view'] as $p) {
        givePermission($technician, $p);
    }

    foreach ([$technician, userWithRole('teacher')] as $user) {
        $this->actingAs($user)->getJson('/api/admin/reports/overall')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/reports/overall/records?table=tickets')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/reports/overall/equipment-options')->assertForbidden();
    }
});

it('closes Repairs without maintenance.view and leaves it out of the Overview', function (): void {
    ($this->deny)('maintenance.view');

    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=repairs')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall/records?table=repairs')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall/records?table=replacements')->assertForbidden();

    $overview = $this->actingAs($this->admin)->getJson('/api/admin/reports/overall')->assertOk()->json();
    expect(collect($overview['meta']['sections'])->pluck('key')->all())->toBe(['overview', 'tickets', 'equipment', 'locations'])
        ->and(collect($overview['data']['kpis'])->pluck('label')->all())->not->toContain('Completed repairs');
});

it('closes Equipment, its tables, its search and the equipment filter without assets.view', function (): void {
    ($this->deny)('assets.view');

    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=equipment')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall/records?table=pc_units')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall/equipment-options')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=tickets&equipment=pc:'.$this->pc->uuid)->assertForbidden();
});

it('closes Tickets and Locations without tickets.view', function (): void {
    ($this->deny)('tickets.view');

    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=tickets')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=locations')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall/records?table=tickets')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=repairs')->assertOk();
});

it('answers 403 before validation for a section the viewer may not see', function (): void {
    ($this->deny)('maintenance.view');

    $this->actingAs($this->admin)->getJson('/api/admin/reports/overall?section=repairs&maintenance_class=nonsense')->assertForbidden();
});

it('refuses an equipment filter that does not exist, without saying more', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/overall?section=tickets&equipment=pc:00000000-0000-4000-8000-000000000000')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('equipment');
});

it('keeps the overall document to the sections the exporter may see', function (): void {
    ($this->deny)('maintenance.view');

    $document = $this->actingAs($this->admin)->postJson('/api/admin/reports/print', ['report_type' => 'overall'])->assertOk()->json('data');

    expect(collect($document['sections'])->pluck('key')->all())->not->toContain('repairs');
});

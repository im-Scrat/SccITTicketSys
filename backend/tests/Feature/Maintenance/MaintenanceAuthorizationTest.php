<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Models\Permission;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;

/*
 * The same contract as MaintenanceVisibilityTest, asserted against real HTTP
 * (SRS FR-MNT-011; SDD DD-55).
 *
 * The service-level suite proves the rule and the policy agree. This one proves
 * the **routes** do — that no controller forgets to authorize, that a direct
 * uuid is not a way past a scoped list, and that a Teacher meets a wall at every
 * single endpoint rather than an empty list that would misrepresent the module
 * as having nothing in it.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(ChecklistTemplateSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

/* ------------------------------------------------------------- unauthenticated */

it('requires authentication for every maintenance endpoint', function () {
    $record = maintenanceFor($this->technician);

    $this->getJson('/api/maintenance')->assertUnauthorized();
    $this->getJson('/api/maintenance/history')->assertUnauthorized();
    $this->getJson('/api/maintenance/scheduled')->assertUnauthorized();
    $this->getJson('/api/maintenance/options')->assertUnauthorized();
    $this->getJson("/api/maintenance/{$record->uuid}")->assertUnauthorized();
    $this->getJson("/api/maintenance/{$record->uuid}/audit")->assertUnauthorized();
    $this->getJson('/api/admin/maintenance')->assertUnauthorized();
    $this->getJson('/api/admin/maintenance/dashboard')->assertUnauthorized();
});

/* -------------------------------------------------------------------- teacher */

it('refuses a teacher every maintenance endpoint with 403, never an empty list', function () {
    $record = maintenanceFor($this->technician);

    $this->actingAs($this->teacher)->getJson('/api/maintenance')->assertForbidden();
    $this->actingAs($this->teacher)->getJson('/api/maintenance/history')->assertForbidden();
    $this->actingAs($this->teacher)->getJson('/api/maintenance/scheduled')->assertForbidden();
    $this->actingAs($this->teacher)->getJson('/api/maintenance/options')->assertForbidden();
    $this->actingAs($this->teacher)->getJson("/api/maintenance/{$record->uuid}")->assertForbidden();
    $this->actingAs($this->teacher)->getJson("/api/maintenance/{$record->uuid}/audit")->assertForbidden();
    $this->actingAs($this->teacher)->getJson('/api/admin/maintenance')->assertForbidden();
    $this->actingAs($this->teacher)->getJson('/api/admin/maintenance/dashboard')->assertForbidden();
});

/* ----------------------------------------------------------------- technician */

it('closes the administrative directory and dashboard to a technician', function () {
    $this->actingAs($this->technician)->getJson('/api/admin/maintenance')->assertForbidden();
    $this->actingAs($this->technician)->getJson('/api/admin/maintenance/dashboard')->assertForbidden();
});

it('refuses a technician direct access to another technician record', function () {
    $theirs = maintenanceFor($this->otherTechnician);

    $this->actingAs($this->technician)
        ->getJson("/api/maintenance/{$theirs->uuid}")
        ->assertForbidden();

    $this->actingAs($this->technician)
        ->getJson("/api/maintenance/{$theirs->uuid}/audit")
        ->assertForbidden();
});

/*
 * The IDOR pair, stated as one test: whatever the list omits, the uuid must
 * refuse. This is the assertion the whole visibility design exists to make
 * true, so it is asserted end to end rather than inferred from two other tests.
 */
it('makes a record absent from a technician list equally unreachable by identifier', function () {
    $mine = maintenanceFor($this->technician);
    $theirs = maintenanceFor($this->otherTechnician);

    $listed = collect(
        $this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data')
    )->pluck('id')->all();

    expect($listed)->toContain($mine->uuid)->not->toContain($theirs->uuid);

    $this->actingAs($this->technician)->getJson("/api/maintenance/{$mine->uuid}")->assertOk();
    $this->actingAs($this->technician)->getJson("/api/maintenance/{$theirs->uuid}")->assertForbidden();
});

it('reaches a record the technician opened but no longer holds', function () {
    // FR-MNT-011 is "created **or** assigned" — reassignment must not erase the
    // opener's own history of the job.
    $record = maintenanceFor($this->technician, overrides: [
        'technician_id' => $this->otherTechnician->id,
        'created_by' => $this->technician->id,
    ]);

    $this->actingAs($this->technician)
        ->getJson("/api/maintenance/{$record->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $record->uuid);
});

it('keeps the personal queue personal even for an administrator', function () {
    // "My maintenance" must mean the same thing to every role, or the page lies
    // to one of them. The estate view is the administrative directory.
    $mine = maintenanceFor($this->admin);
    $theirs = maintenanceFor($this->technician);

    $listed = collect(
        $this->actingAs($this->admin)->getJson('/api/maintenance')->assertOk()->json('data')
    )->pluck('id')->all();

    expect($listed)->toContain($mine->uuid)->not->toContain($theirs->uuid);

    // …while the directory is genuinely the estate.
    $directory = collect(
        $this->actingAs($this->admin)->getJson('/api/admin/maintenance')->assertOk()->json('data')
    )->pluck('id')->all();

    expect($directory)->toContain($mine->uuid)->toContain($theirs->uuid);
});

/* --------------------------------------------------------- per-user overrides */

it('closes the module to a technician whose maintenance.view is denied', function () {
    $record = maintenanceFor($this->technician);

    $this->technician->directPermissions()->attach(
        Permission::query()->where('name', 'maintenance.view')->value('id'),
        ['grant_type' => 'deny'],
    );

    $this->actingAs($this->technician)->getJson('/api/maintenance')->assertForbidden();
    $this->actingAs($this->technician)->getJson("/api/maintenance/{$record->uuid}")->assertForbidden();
});

/* ------------------------------------------------------------ payload shaping */

it('withholds the technician roster and template catalogue from a technician', function () {
    $payload = $this->actingAs($this->technician)
        ->getJson('/api/maintenance/options')
        ->assertOk()
        ->json('data');

    // Offering controls the API would refuse is a form that lies about itself.
    expect($payload['technicians'])->toBe([])
        ->and($payload['checklist_templates'])->toBe([])
        // The vocabulary they *do* need is present.
        ->and($payload['types'])->not->toBeEmpty()
        ->and($payload['statuses'])->toHaveCount(count(MaintenanceStatus::cases()));
});

it('gives an administrator the roster and the template catalogue', function () {
    $payload = $this->actingAs($this->admin)
        ->getJson('/api/maintenance/options')
        ->assertOk()
        ->json('data');

    expect($payload['technicians'])->not->toBeEmpty()
        ->and($payload['checklist_templates'])->not->toBeEmpty();
});

it('flags which maintenance types will require evidence to complete', function () {
    $types = collect(
        $this->actingAs($this->technician)->getJson('/api/maintenance/options')->assertOk()->json('data.types')
    )->keyBy('value');

    // The Client's business rule of 2026-08-29, stated type by type. The API
    // says this up front so the form can warn a technician *before* they start
    // that the job will need a photograph to finish — the same rule the
    // lifecycle enforces at the end, surfaced early rather than at the wall.
    expect($types['corrective']['requires_evidence'])->toBeTrue()
        ->and($types['hardware-upgrade']['requires_evidence'])->toBeTrue()
        ->and($types['preventive']['requires_evidence'])->toBeFalse()
        ->and($types['inspection']['requires_evidence'])->toBeFalse()
        ->and($types['cleaning']['requires_evidence'])->toBeFalse();
});

/* ------------------------------------------------------------- uuid handling */

it('answers a non-uuid path segment with 404 rather than a database error', function () {
    $this->actingAs($this->technician)
        ->getJson('/api/maintenance/not-a-uuid')
        ->assertNotFound();
});

it('answers an unknown uuid with 404', function () {
    $this->actingAs($this->technician)
        ->getJson('/api/maintenance/'.fake()->uuid())
        ->assertNotFound();
});

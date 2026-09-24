<?php

declare(strict_types=1);

use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;

/*
 * The WP-2.6 authorization contract (SRS FR-MNT-011; SDD DD-55).
 *
 * Maintenance is the second module whose *permission* does not separate the
 * roles: `maintenance.*` is seeded to Administrators and Technicians alike. What
 * separates them is which rows each may reach:
 *
 *   Administrator  every record
 *   Technician     records they were assigned OR opened, and no others
 *   Teacher        none at all — they hold no maintenance permission
 *
 * The load-bearing assertion in this file is the **equivalence**: whatever
 * `scope()` excludes from a list, `levelFor()` must refuse for a single record.
 * Two definitions of "mine" would eventually disagree, and the disagreement
 * would be an IDOR. The HTTP-level proof of the same rule lives in
 * MaintenanceAuthorizationTest; this file pins the rule itself.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->visibility = app(MaintenanceVisibility::class);
});

/** Every record this user can reach through the list path. */
function visibleIds(User $user): array
{
    return app(MaintenanceVisibility::class)
        ->scope(MaintenanceRecord::query(), $user)
        ->pluck('maintenance_records.id')
        ->all();
}

/* ------------------------------------------------------------- the levels */

it('gives an administrator the full projection of every record', function () {
    $record = maintenanceFor($this->otherTechnician);

    expect($this->visibility->levelFor($this->admin, $record))
        ->toBe(MaintenanceVisibility::LEVEL_FULL)
        ->and(visibleIds($this->admin))->toContain($record->id);
});

it('gives a technician the full projection of a record assigned to them', function () {
    $record = maintenanceFor($this->technician);

    expect($this->visibility->levelFor($this->technician, $record))
        ->toBe(MaintenanceVisibility::LEVEL_FULL);
});

it('reaches a record a technician opened but no longer holds', function () {
    // FR-MNT-011 says "created **or** assigned". An administrator handing the
    // job to someone else must not erase the opener's own history of it.
    $record = maintenanceFor($this->technician, overrides: [
        'technician_id' => $this->otherTechnician->id,
        'created_by' => $this->technician->id,
    ]);

    expect($this->visibility->levelFor($this->technician, $record))
        ->toBe(MaintenanceVisibility::LEVEL_FULL)
        ->and(visibleIds($this->technician))->toContain($record->id);
});

it('refuses a technician a record that is neither theirs nor opened by them', function () {
    $record = maintenanceFor($this->otherTechnician);

    expect($this->visibility->levelFor($this->technician, $record))
        ->toBe(MaintenanceVisibility::LEVEL_NONE)
        ->and($this->visibility->canSee($this->technician, $record))->toBeFalse();
});

it('refuses a teacher every record, because they hold no maintenance permission', function () {
    $record = maintenanceFor($this->technician);

    expect($this->visibility->levelFor($this->teacher, $record))
        ->toBe(MaintenanceVisibility::LEVEL_NONE)
        ->and(visibleIds($this->teacher))->toBe([]);
});

/* ------------------------------------------------- list / record equivalence */

it('excludes from the list exactly what it refuses by identifier', function () {
    $mine = maintenanceFor($this->technician);
    $opened = maintenanceFor($this->technician, overrides: [
        'technician_id' => $this->otherTechnician->id,
        'created_by' => $this->technician->id,
    ]);
    $theirs = maintenanceFor($this->otherTechnician);

    $listed = visibleIds($this->technician);

    // Every record in the list resolves; every record out of it refuses. This
    // is the property that makes a guessed uuid useless.
    foreach ([$mine, $opened, $theirs] as $record) {
        expect($this->visibility->canSee($this->technician, $record))
            ->toBe(in_array($record->id, $listed, true));
    }

    expect($listed)->toHaveCount(2)->not->toContain($theirs->id);
});

it('returns no rows at all when the permission is revoked by a per-user deny', function () {
    $record = maintenanceFor($this->technician);

    $this->technician->directPermissions()->attach(
        Permission::query()->where('name', 'maintenance.view')->value('id'),
        ['grant_type' => 'deny'],
    );
    $this->technician->refresh();

    expect(visibleIds($this->technician))->toBe([])
        ->and($this->visibility->canSee($this->technician, $record))->toBeFalse();
});

/* --------------------------------------------------------- write abilities */

it('lets the owning technician work an open record and stops at completion', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    expect($this->visibility->canWork($this->technician, $record))->toBeTrue();

    // Read outlives the visit; every write ability lapses with it (DD-42's
    // stance, restated for maintenance).
    $record->update(['status' => MaintenanceStatus::Completed->value]);

    expect($this->visibility->canWork($this->technician, $record))->toBeFalse()
        ->and($this->visibility->canSee($this->technician, $record))->toBeTrue();
});

it('refuses a technician write access to another technician record', function () {
    $record = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    expect($this->visibility->canWork($this->technician, $record))->toBeFalse();
});

it('reserves reassignment for administrators', function () {
    expect($this->visibility->canReassign($this->admin))->toBeTrue()
        ->and($this->visibility->canReassign($this->technician))->toBeFalse();
});

it('reserves the administrative surface and the catalogue for administrators', function () {
    expect($this->visibility->canSeeAdministrative($this->admin))->toBeTrue()
        ->and($this->visibility->canConfigureCatalog($this->admin))->toBeTrue()
        ->and($this->visibility->canSeeAdministrative($this->technician))->toBeFalse()
        ->and($this->visibility->canConfigureCatalog($this->technician))->toBeFalse();
});

/* ------------------------------------------------------- the archive ceiling */

it('lets a technician archive only their own untouched or cancelled work', function () {
    $scheduled = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);
    $cancelled = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Cancelled->value]);
    $inProgress = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $completed = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);

    expect($this->visibility->canArchive($this->technician, $scheduled))->toBeTrue()
        ->and($this->visibility->canArchive($this->technician, $cancelled))->toBeTrue()
        // A record describing work that actually happened is audit material.
        ->and($this->visibility->canArchive($this->technician, $inProgress))->toBeFalse()
        ->and($this->visibility->canArchive($this->technician, $completed))->toBeFalse();
});

it('lets an administrator archive a completed record a technician may not', function () {
    $completed = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);

    expect($this->visibility->canArchive($this->admin, $completed))->toBeTrue();
});

it('refuses a technician archiving another technician record in any status', function () {
    $record = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);

    expect($this->visibility->canArchive($this->technician, $record))->toBeFalse();
});

/* --------------------------------------------------------------- the policy */

it('answers through the policy exactly as the service does', function () {
    $mine = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $theirs = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    expect($this->technician->can('view', $mine))->toBeTrue()
        ->and($this->technician->can('view', $theirs))->toBeFalse()
        ->and($this->technician->can('update', $mine))->toBeTrue()
        ->and($this->technician->can('update', $theirs))->toBeFalse()
        ->and($this->technician->can('complete', $mine))->toBeTrue()
        ->and($this->technician->can('reassign', $mine))->toBeFalse()
        ->and($this->technician->can('viewAdministrative', MaintenanceRecord::class))->toBeFalse()
        ->and($this->admin->can('viewAdministrative', MaintenanceRecord::class))->toBeTrue();
});

it('never permits a hard delete, whatever the role', function () {
    $record = maintenanceFor($this->technician);

    expect($this->admin->can('forceDelete', $record))->toBeFalse()
        ->and($this->technician->can('forceDelete', $record))->toBeFalse();
});

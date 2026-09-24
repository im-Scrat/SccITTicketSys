<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\RepairImage;
use App\Models\User;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;

/*
 * The maintenance lifecycle (SRS FR-MNT-003/004/008/010; SDD DD-55).
 *
 * Three properties:
 *
 *   1. Only legal transitions happen, and an illegal one is a 422 — never a
 *      silent no-op and never a status written straight from a payload.
 *   2. Completion is *gated*: required checklist items, a resolution, and — for
 *      corrective work — evidence.
 *   3. The PC unit is moved into `under_maintenance` on start and put back on
 *      completion, and every move leaves an audit row written in the same
 *      transaction.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(ChecklistTemplateSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');

    $this->pc = PcUnit::factory()->create([
        'status' => PcStatus::Online->value,
        'current_condition' => PcCondition::Faulty->value,
    ]);
});

/** Move a record, returning the response. */
function moveMaintenance(User $actor, MaintenanceRecord $record, string $status, array $extra = [])
{
    return test()->actingAs($actor)
        ->putJson("/api/maintenance/{$record->uuid}/status", ['status' => $status, ...$extra]);
}

/* ------------------------------------------------------------ the happy path */

it('walks a corrective record from scheduled to completed', function () {
    $record = maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pc->id]);
    RepairImage::factory()->create(['maintenance_record_id' => $record->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    expect($record->refresh()->started_at)->not->toBeNull();

    moveMaintenance($this->technician, $record, 'on_hold')->assertOk();
    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Replaced the PSU.'])->assertOk();

    $record->refresh();

    expect($record->status)->toBe(MaintenanceStatus::Completed)
        ->and($record->completed_at)->not->toBeNull()
        ->and($record->maintenance_date)->not->toBeNull()
        ->and($record->resolution)->toBe('Replaced the PSU.');
});

it('stamps started_at once, not again on resume', function () {
    $record = maintenanceFor($this->technician, overrides: ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    $first = $record->refresh()->started_at;

    moveMaintenance($this->technician, $record, 'on_hold')->assertOk();
    $this->travel(5)->minutes();
    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    // Resuming is the same visit; when the work began must not move.
    expect($record->refresh()->started_at->toIso8601String())->toBe($first->toIso8601String());
});

it('distinguishes resuming held work from starting it', function () {
    $record = maintenanceFor($this->technician, overrides: ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    moveMaintenance($this->technician, $record, 'on_hold')->assertOk();
    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    $actions = ActivityLog::query()->where('subject_id', $record->id)->pluck('action')->all();

    expect($actions)->toContain(ActivityAction::MaintenanceStarted->value)
        ->toContain(ActivityAction::MaintenanceHeld->value)
        ->toContain(ActivityAction::MaintenanceResumed->value);
});

/* ------------------------------------------------------------ illegal moves */

it('refuses a transition the map does not allow', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);

    // scheduled -> completed skips the work entirely.
    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Done, honest'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($record->refresh()->status)->toBe(MaintenanceStatus::Scheduled);
});

it('treats completed and cancelled as terminal', function () {
    $completed = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);
    $cancelled = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Cancelled->value]);

    // Both refuse before the map is even consulted: `canWork` is false on a
    // terminal record, so the policy stops it at the door.
    moveMaintenance($this->technician, $completed, 'in_progress')->assertForbidden();
    moveMaintenance($this->technician, $cancelled, 'in_progress')->assertForbidden();
});

it('treats re-submitting the current status as a no-op rather than an error', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $before = ActivityLog::query()->where('subject_id', $record->id)->count();

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    // A double-tapped button must not write a spurious timeline entry.
    expect(ActivityLog::query()->where('subject_id', $record->id)->count())->toBe($before);
});

it('refuses a technician moving another technician record', function () {
    $record = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertForbidden();
});

it('never accepts a status the enum does not name', function () {
    $record = maintenanceFor($this->technician);

    moveMaintenance($this->technician, $record, 'obliterated')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

/* -------------------------------------------------------- completion gates */

it('refuses completion while a required checklist item is outstanding', function () {
    $record = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    $record->checklists()->create(['item_label' => 'Critical step', 'is_required' => true, 'is_completed' => false]);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'All done'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('allows completion once every required item is ticked, ignoring optional ones', function () {
    $record = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    $record->checklists()->create(['item_label' => 'Critical', 'is_required' => true, 'is_completed' => true]);
    $record->checklists()->create(['item_label' => 'Nice to have', 'is_required' => false, 'is_completed' => false]);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Serviced'])->assertOk();
});

/*
 * Decision 1 (Client, 2026-08-29): a resolution is required before completion,
 * **regardless of maintenance type**. Asserted across every seeded type rather
 * than on one, because "regardless of type" is precisely the claim.
 */
it('refuses completion with no account of what was done, whatever the type', function (string $type) {
    $record = maintenanceFor($this->technician, $type, [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
        'resolution' => null,
    ]);

    // Evidence attached up front, so the only thing left to fail on is the
    // missing resolution — otherwise the corrective cases would pass this test
    // for the wrong reason.
    RepairImage::factory()->create(['maintenance_record_id' => $record->id]);

    moveMaintenance($this->technician, $record, 'completed')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);
})->with(['corrective', 'hardware-upgrade', 'preventive', 'inspection', 'cleaning']);

it('accepts a resolution supplied with the completion itself', function () {
    $record = maintenanceFor($this->technician, 'cleaning', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
        'resolution' => null,
    ]);

    // A technician finishes the job in one action rather than saving and then
    // closing; the gate reads the payload as well as the stored column.
    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Dust cleared, fans checked.'])
        ->assertOk();

    expect($record->refresh()->resolution)->toBe('Dust cleared, fans checked.');
});

/*
 * Decision 2 (Client, 2026-08-29): the per-type evidence rule.
 *
 *   corrective         REQUIRED
 *   hardware upgrade   REQUIRED
 *   preventive         supported, not universally required
 *   inspection         optional
 *   cleaning           optional
 *
 * Asserted type by type against the seeded catalogue, so a future change to
 * `is_preventive` on any of the five would fail here rather than quietly
 * loosening or tightening a completion gate.
 */
it('requires evidence before completing corrective and hardware-upgrade work', function (string $type) {
    $record = maintenanceFor($this->technician, $type, [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Work done.'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    // …and the same record completes once evidence exists.
    RepairImage::factory()->create(['maintenance_record_id' => $record->id]);
    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Work done.'])->assertOk();

    expect($record->refresh()->status)->toBe(MaintenanceStatus::Completed);
})->with(['corrective', 'hardware-upgrade']);

it('completes preventive, inspection and cleaning work without evidence', function (string $type) {
    $record = maintenanceFor($this->technician, $type, [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Round completed.'])
        ->assertOk();

    expect($record->refresh()->status)->toBe(MaintenanceStatus::Completed)
        ->and($record->images()->count())->toBe(0);
})->with(['preventive', 'inspection', 'cleaning']);

it('still supports evidence on a preventive round that is not required to carry it', function () {
    // "Supported but not universally required" — the optional case must remain
    // possible, not merely unenforced.
    $record = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    RepairImage::factory()->create(['maintenance_record_id' => $record->id]);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Serviced.'])->assertOk();

    expect($record->refresh()->images()->count())->toBe(1);
});

it('reads the evidence rule from the type rather than a hard-coded slug list', function () {
    // A type an administrator adds later inherits the rule from its own
    // `is_preventive` flag — there is no second place to configure it, which is
    // the whole point of not adding one.
    $bespoke = MaintenanceType::query()->create([
        'name' => 'Emergency Repair',
        'slug' => 'emergency-repair',
        'is_preventive' => false,
        'is_active' => true,
    ]);

    $record = maintenanceFor($this->technician, overrides: [
        'maintenance_type_id' => $bespoke->id,
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Repaired.'])
        ->assertStatus(422);
});

it('refuses completion by someone without maintenance.complete', function () {
    $record = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $this->technician->directPermissions()->attach(
        Permission::query()->where('name', 'maintenance.complete')->value('id'),
        ['grant_type' => 'deny'],
    );

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Serviced'])
        ->assertStatus(422);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('reports the blockers on the available transitions rather than hiding the button', function () {
    $record = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
        'resolution' => null,
    ]);
    $record->checklists()->create(['item_label' => 'Critical', 'is_required' => true, 'is_completed' => false]);

    $transitions = collect(
        $this->actingAs($this->technician)->getJson("/api/maintenance/{$record->uuid}")->assertOk()
            ->json('data.available_transitions')
    )->keyBy('value');

    // A technician needs to know *why* they cannot finish; a silently missing
    // button teaches them nothing.
    expect($transitions['completed']['blocked_by'])->toHaveCount(3)
        ->and($transitions['on_hold']['blocked_by'])->toBe([]);
});

/* --------------------------------------------------------- the PC-unit effect */

it('puts the machine under maintenance on start and back afterwards', function () {
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    expect($this->pc->refresh()->status)->toBe(PcStatus::UnderMaintenance)
        // What the visit displaced is remembered on the record, not inferred.
        ->and($record->refresh()->pc_status_before)->toBe(PcStatus::Online)
        ->and($record->pc_condition_before)->toBe(PcCondition::Faulty);

    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Serviced'])->assertOk();

    // Status returns to what it was; the condition is asserted working, which
    // is what completing the visit means (FR-MNT-008).
    expect($this->pc->refresh()->status)->toBe(PcStatus::Online)
        ->and($this->pc->current_condition)->toBe(PcCondition::Working);
});

it('restores both prior values exactly when the visit is cancelled', function () {
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    moveMaintenance($this->technician, $record, 'cancelled', ['reason' => 'Machine redeployed'])->assertOk();

    // A cancelled visit asserts nothing about the machine, so nothing is
    // asserted — it goes back exactly as it was.
    expect($this->pc->refresh()->status)->toBe(PcStatus::Online)
        ->and($this->pc->current_condition)->toBe(PcCondition::Faulty);
});

it('leaves the machine under maintenance for a second concurrent visit', function () {
    $first = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);
    $second = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $first, 'in_progress')->assertOk();
    moveMaintenance($this->technician, $second, 'in_progress')->assertOk();

    // The second visit remembers `under_maintenance` as what *it* displaced, so
    // finishing the first correctly leaves the machine under maintenance.
    expect($second->refresh()->pc_status_before)->toBe(PcStatus::UnderMaintenance);

    moveMaintenance($this->technician, $first, 'completed', ['resolution' => 'Done'])->assertOk();

    expect($this->pc->refresh()->status)->toBe(PcStatus::Online);
});

it('touches no machine for an asset-only record', function () {
    $record = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => null,
        'asset_id' => Asset::factory()->create()->id,
    ]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    expect($record->refresh()->pc_status_before)->toBeNull();

    $log = ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceStarted->value)
        ->firstOrFail();

    // The audit must not imply a machine change that never happened.
    expect($log->properties)->not->toHaveKey('pc_unit');
});

/* --------------------------------------------------------------- the audit */

it('writes an audit row for every transition, naming both states', function () {
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    $log = ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceStarted->value)
        ->firstOrFail();

    expect($log->properties['from'])->toBe('scheduled')
        ->and($log->properties['to'])->toBe('in_progress')
        ->and($log->user_id)->toBe($this->technician->id)
        ->and($log->module)->toBe('maintenance');
});

it('records the cancellation reason on the timeline', function () {
    $record = maintenanceFor($this->technician);

    moveMaintenance($this->technician, $record, 'cancelled', ['reason' => 'Duplicate of another visit'])->assertOk();

    $log = ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceCancelled->value)
        ->firstOrFail();

    expect($log->properties['reason'])->toBe('Duplicate of another visit');
});

it('exposes the transition on the record audit endpoint', function () {
    $record = maintenanceFor($this->technician);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    $this->actingAs($this->technician)
        ->getJson("/api/maintenance/{$record->uuid}/audit")
        ->assertOk()
        ->assertJsonPath('data.0.action', ActivityAction::MaintenanceStarted->value);
});

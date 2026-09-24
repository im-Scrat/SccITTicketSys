<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\ChecklistTemplateItem;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Opening, editing, reassigning and archiving maintenance
 * (SRS FR-MNT-001/002/003/011).
 *
 * The recurring theme: what the server decides and what the client may say are
 * two different sets, and this file pins the boundary between them. `status`,
 * `created_by`, `technician_id` (for a technician) and every timestamp are the
 * server's; a payload that tries to set one must not succeed quietly.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(ChecklistTemplateSeeder::class);
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->pc = PcUnit::factory()->create();
});

/* --------------------------------------------------------------- creating */

it('opens a corrective record against a PC unit', function () {
    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Replace failing PSU',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
            'diagnosis' => 'Unit powers down under load.',
        ])
        ->assertCreated();

    $record = MaintenanceRecord::query()->where('uuid', $response->json('data.id'))->firstOrFail();

    expect($record->status)->toBe(MaintenanceStatus::Scheduled)
        ->and($record->technician_id)->toBe($this->technician->id)
        ->and($record->created_by)->toBe($this->technician->id)
        ->and($record->pc_unit_id)->toBe($this->pc->id)
        ->and($record->ticket_id)->toBeNull();
});

it('opens a preventive record with a schedule and no ticket', function () {
    // FR-MNT-002 is exactly this shape: nullable ticket, dated.
    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Quarterly service',
            'type' => 'preventive',
            'pc_unit' => $this->pc->uuid,
            'scheduled_for' => now()->addWeek()->toIso8601String(),
        ])
        ->assertCreated();

    $record = MaintenanceRecord::query()->where('uuid', $response->json('data.id'))->firstOrFail();

    expect($record->ticket_id)->toBeNull()
        ->and($record->scheduled_for)->not->toBeNull()
        ->and($record->type->is_preventive)->toBeTrue();
});

it('opens a record against a standalone asset', function () {
    $asset = Asset::factory()->create();

    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Projector lamp replacement',
            'type' => 'corrective',
            'asset' => $asset->uuid,
        ])
        ->assertCreated();

    expect($response->json('data.target.kind'))->toBe('asset');
});

it('links a record to the ticket the work arose from', function () {
    $ticket = ticketFor($this->teacher);

    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Fix reported fault',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
            'ticket' => $ticket->uuid,
        ])
        ->assertCreated();

    expect($response->json('data.ticket.number'))->toBe($ticket->ticket_number);
});

it('issues the type default checklist at creation', function () {
    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Quarterly service',
            'type' => 'preventive',
            'pc_unit' => $this->pc->uuid,
        ])
        ->assertCreated();

    // Seeded "Preventive Maintenance — Standard" carries seven items, three of
    // them required.
    expect($response->json('data.checklist.total'))->toBe(7)
        ->and($response->json('data.checklist.completed'))->toBe(0);

    $required = collect($response->json('data.checklist_items'))->where('is_required', true);
    expect($required)->toHaveCount(3);
});

it('copies the required flag onto the instance so a template edit cannot disarm it', function () {
    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Quarterly service',
            'type' => 'preventive',
            'pc_unit' => $this->pc->uuid,
        ])
        ->assertCreated();

    $record = MaintenanceRecord::query()->where('uuid', $response->json('data.id'))->firstOrFail();

    // Delete the whole template catalogue out from under the issued record.
    ChecklistTemplateItem::query()->delete();

    expect($record->checklists()->where('is_required', true)->count())->toBe(3);
});

it('warns about concurrent maintenance without refusing the record', function () {
    $existing = maintenanceFor($this->otherTechnician, 'preventive', [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::Scheduled->value,
    ]);

    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Urgent corrective repair',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
        ])
        // A machine may legitimately carry a preventive visit and a corrective
        // repair at once, so this is advice, not a wall.
        ->assertCreated();

    $concurrent = $response->json('meta.concurrent');

    expect($concurrent)->toHaveCount(1)
        ->and($concurrent[0]['id'])->toBe($existing->uuid)
        // The warning must not become a disclosure channel: no diagnosis,
        // resolution, cost or evidence about another technician's job.
        ->and($concurrent[0])->not->toHaveKey('diagnosis')
        ->and($concurrent[0])->not->toHaveKey('resolution')
        ->and($concurrent[0])->not->toHaveKey('cost');
});

/* -------------------------------------------------- creation: what is refused */

it('refuses a record naming neither a PC unit nor an asset', function () {
    $this->actingAs($this->technician)
        ->postJson('/api/maintenance', ['title' => 'Nothing in particular', 'type' => 'corrective'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pc_unit');
});

it('refuses a technician opening work assigned to someone else', function () {
    $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Someone else can do it',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
            'technician' => $this->otherTechnician->uuid,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('technician');
});

it('lets an administrator open work on a technician behalf', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/maintenance', [
            'title' => 'Scheduled for the team',
            'type' => 'preventive',
            'pc_unit' => $this->pc->uuid,
            'technician' => $this->technician->uuid,
        ])
        ->assertCreated();

    $record = MaintenanceRecord::query()->where('uuid', $response->json('data.id'))->firstOrFail();

    // Assigned to one person, opened by another — both halves of FR-MNT-011.
    expect($record->technician_id)->toBe($this->technician->id)
        ->and($record->created_by)->toBe($this->admin->id);
});

it('ignores a client-supplied status and opens every record scheduled', function () {
    $response = $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Sneaky',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
            'status' => 'completed',
            'created_by' => $this->admin->id,
            'completed_at' => now()->toIso8601String(),
        ])
        ->assertCreated();

    $record = MaintenanceRecord::query()->where('uuid', $response->json('data.id'))->firstOrFail();

    expect($record->status)->toBe(MaintenanceStatus::Scheduled)
        ->and($record->completed_at)->toBeNull()
        ->and($record->created_by)->toBe($this->technician->id);
});

it('refuses negative metrics at the request rather than the database', function () {
    $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Impossible',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
            'downtime_minutes' => -5,
            'labor_hours' => -1,
            'cost' => -100,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['downtime_minutes', 'labor_hours', 'cost']);
});

it('refuses an archived PC unit as a target', function () {
    $this->pc->delete();

    $this->actingAs($this->technician)
        ->postJson('/api/maintenance', [
            'title' => 'Work on an archived machine',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pc_unit');
});

it('refuses a teacher opening maintenance at all', function () {
    $this->actingAs($this->teacher)
        ->postJson('/api/maintenance', [
            'title' => 'Not my job',
            'type' => 'corrective',
            'pc_unit' => $this->pc->uuid,
        ])
        ->assertForbidden();
});

/* --------------------------------------------------------------- updating */

it('edits an open record and audits the field-level diff', function () {
    $record = maintenanceFor($this->technician, overrides: ['diagnosis' => 'Original']);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}", ['diagnosis' => 'Revised after inspection'])
        ->assertOk()
        ->assertJsonPath('data.diagnosis', 'Revised after inspection');

    $log = ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceUpdated->value)
        ->latest('id')
        ->firstOrFail();

    // `toMatchArray`, not `toBe`: `properties` is a jsonb column and Postgres
    // stores object keys in its own order, so asserting key order would be
    // asserting a property of the database rather than of the audit entry.
    expect($log->properties['changes']['diagnosis'])
        ->toMatchArray(['from' => 'Original', 'to' => 'Revised after inspection']);
});

it('records a reschedule as its own event preserving the previous date', function () {
    $record = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->addWeek()]);
    $was = $record->scheduled_for->toIso8601String();

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}", [
            'scheduled_for' => now()->addWeeks(3)->toIso8601String(),
            'reschedule_reason' => 'Awaiting a part',
        ])
        ->assertOk();

    $log = ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceRescheduled->value)
        ->firstOrFail();

    // The prior value stays recoverable rather than being silently overwritten.
    expect($log->properties['from'])->toBe($was)
        ->and($log->properties['reason'])->toBe('Awaiting a part');
});

it('refuses editing a completed record', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}", ['diagnosis' => 'Rewriting history'])
        ->assertForbidden();
});

it('refuses a technician editing another technician record', function () {
    $record = maintenanceFor($this->otherTechnician);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}", ['diagnosis' => 'Not mine'])
        ->assertForbidden();
});

it('does not let an edit repoint the record at a different machine', function () {
    $record = maintenanceFor($this->technician, overrides: ['pc_unit_id' => $this->pc->id]);
    $other = PcUnit::factory()->create();

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}", [
            'pc_unit' => $other->uuid,
            'asset_id' => 999,
        ])
        ->assertOk();

    // Repointing would rewrite two service histories at once, so the field is
    // simply not part of the edit surface.
    expect($record->refresh()->pc_unit_id)->toBe($this->pc->id);
});

/* ------------------------------------------------------------ reassignment */

it('lets an administrator reassign and refuses a technician', function () {
    $record = maintenanceFor($this->technician);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/reassign", ['technician' => $this->otherTechnician->uuid])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->postJson("/api/maintenance/{$record->uuid}/reassign", ['technician' => $this->otherTechnician->uuid])
        ->assertOk();

    expect($record->refresh()->technician_id)->toBe($this->otherTechnician->id);

    $log = ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceReassigned->value)
        ->firstOrFail();

    expect($log->properties['from'])->toBe($this->technician->uuid)
        ->and($log->properties['to'])->toBe($this->otherTechnician->uuid);
});

it('keeps a reassigned record reachable by the technician who opened it', function () {
    $record = maintenanceFor($this->technician);

    $this->actingAs($this->admin)
        ->postJson("/api/maintenance/{$record->uuid}/reassign", ['technician' => $this->otherTechnician->uuid])
        ->assertOk();

    // FR-MNT-011 is "assigned **or** created" — handing the job on must not
    // erase the opener's history of it.
    $this->actingAs($this->technician)->getJson("/api/maintenance/{$record->uuid}")->assertOk();
    $this->actingAs($this->otherTechnician)->getJson("/api/maintenance/{$record->uuid}")->assertOk();
});

/* --------------------------------------------------------------- archiving */

it('lets a technician archive their own scheduled work but not work in progress', function () {
    $scheduled = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);
    $inProgress = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $completed = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);

    $this->actingAs($this->technician)->deleteJson("/api/maintenance/{$scheduled->uuid}")->assertOk();

    // Work that actually happened is audit material; the person accountable for
    // it is the last one who should be able to remove it.
    $this->actingAs($this->technician)->deleteJson("/api/maintenance/{$inProgress->uuid}")->assertForbidden();
    $this->actingAs($this->technician)->deleteJson("/api/maintenance/{$completed->uuid}")->assertForbidden();

    expect($scheduled->fresh()->deleted_at)->not->toBeNull()
        ->and($inProgress->fresh()->deleted_at)->toBeNull();
});

it('lets an administrator archive a completed record and restore it', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);

    $this->actingAs($this->admin)->deleteJson("/api/maintenance/{$record->uuid}")->assertOk();
    expect($record->fresh()->deleted_at)->not->toBeNull();

    $this->actingAs($this->admin)->postJson("/api/maintenance/{$record->uuid}/restore")->assertOk();
    expect($record->fresh()->deleted_at)->toBeNull();
});

it('refuses a technician restoring anything', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);
    $record->delete();

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/restore")
        ->assertForbidden();
});

it('drops an archived record out of the lists but keeps it in the audit trail', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Scheduled->value]);

    $this->actingAs($this->technician)->deleteJson("/api/maintenance/{$record->uuid}")->assertOk();

    $ids = collect($this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data'))->pluck('id');
    expect($ids)->not->toContain($record->uuid);

    expect(ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceArchived->value)
        ->exists())->toBeTrue();
});

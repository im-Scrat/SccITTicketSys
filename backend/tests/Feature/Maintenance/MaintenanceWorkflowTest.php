<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\AssetStatus;
use App\Enums\InstallationStatus;
use App\Enums\MaintenanceStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\HardwareComponent;
use App\Models\MaintenanceRecord;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Doing the job: checklists, notes, hardware replacements and the preventive
 * sweep (SRS FR-MNT-004/005/006/007, AC-MNT-006).
 *
 * Evidence *security* has its own file — this one covers evidence only as part
 * of the workflow.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(ChecklistTemplateSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    Storage::fake('local');

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');

    $this->pc = PcUnit::factory()->create();
});

/* ------------------------------------------------------------- checklists */

it('ticks a checklist item and records who did it and when', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $item = $record->checklists()->create(['item_label' => 'Check cooling', 'is_required' => true]);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}/checklist/{$item->id}", [
            'is_completed' => true,
            'remarks' => 'Fans clear',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_completed', true)
        ->assertJsonPath('data.remarks', 'Fans clear');

    $item->refresh();

    // Both are the server's; a checklist whose completion metadata the client
    // could set would not be evidence of anything.
    expect($item->completed_by)->toBe($this->technician->id)
        ->and($item->completed_at)->not->toBeNull();
});

it('clears the completion metadata when an item is unticked', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $item = $record->checklists()->create(['item_label' => 'Check cooling', 'is_completed' => true]);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}/checklist/{$item->id}", ['is_completed' => false])
        ->assertOk();

    $item->refresh();

    // A stale `completed_by` on an unticked row would claim someone completed
    // something they had not.
    expect($item->completed_by)->toBeNull()->and($item->completed_at)->toBeNull();
});

it('audits both ticking and unticking', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $item = $record->checklists()->create(['item_label' => 'Check cooling']);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}/checklist/{$item->id}", ['is_completed' => true])->assertOk();
    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}/checklist/{$item->id}", ['is_completed' => false])->assertOk();

    $actions = ActivityLog::query()->where('subject_id', $record->id)->pluck('action')->all();

    // A correction is visible rather than silent.
    expect($actions)->toContain(ActivityAction::MaintenanceChecklistItemCompleted->value)
        ->toContain(ActivityAction::MaintenanceChecklistItemReopened->value);
});

it('404s a checklist item belonging to another record', function () {
    $mine = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $theirs = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $foreign = $theirs->checklists()->create(['item_label' => 'Not yours']);

    // Nested through the record, so an enumerated id reaches nothing new.
    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$mine->uuid}/checklist/{$foreign->id}", ['is_completed' => true])
        ->assertNotFound();
});

it('refuses ticking anything on a completed record', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);
    $item = $record->checklists()->create(['item_label' => 'Too late']);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}/checklist/{$item->id}", ['is_completed' => true])
        ->assertForbidden();
});

/* ------------------------------------------------------------------- notes */

it('appends an attributable note', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/notes", [
            'body' => 'Waiting on a replacement fan.',
            'technician_id' => $this->admin->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.author.id', $this->technician->uuid);

    // The author is the actor, never the payload.
    expect($record->notes()->first()->technician_id)->toBe($this->technician->id);
});

it('refuses a note on another technician record', function () {
    $record = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/notes", ['body' => 'Not mine'])
        ->assertForbidden();
});

it('refuses an empty note', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/notes", ['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

/* --------------------------------------------------- hardware replacements */

it('records a replacement and reconciles the installation history', function () {
    // AC-MNT-006, verbatim.
    $record = maintenanceFor($this->technician, overrides: [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $oldAsset = Asset::factory()->create(['status' => AssetStatus::Deployed->value]);
    $newAsset = Asset::factory()->create(['status' => AssetStatus::InStock->value]);

    $installation = PcComponentInstallation::factory()->create([
        'pc_unit_id' => $this->pc->id,
        'asset_id' => $oldAsset->id,
        'installation_status' => InstallationStatus::Installed->value,
        'installation_date' => now()->subYear(),
        'removal_date' => null,
    ]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/replacements", [
            'old_asset' => $oldAsset->uuid,
            'new_asset' => $newAsset->uuid,
            'old_component' => HardwareComponent::factory()->create()->id,
            'new_component' => HardwareComponent::factory()->create()->id,
            'quantity' => 1,
            'reason' => 'Drive failed SMART',
            'warranty_months' => 24,
        ])
        ->assertCreated();

    // The replacement is stored…
    expect($record->hardwareReplacements()->count())->toBe(1);

    // …the replaced asset is no longer marked installed in that PC…
    $installation->refresh();
    expect($installation->removal_date)->not->toBeNull()
        ->and($installation->installation_status)->toBe(InstallationStatus::Removed);

    // …and the PC's installation history now names the fitted unit.
    $fitted = PcComponentInstallation::query()
        ->where('pc_unit_id', $this->pc->id)
        ->where('asset_id', $newAsset->id)
        ->whereNull('removal_date')
        ->first();

    expect($fitted)->not->toBeNull()
        ->and($fitted->installed_by)->toBe($this->technician->id)
        ->and($newAsset->refresh()->status)->toBe(AssetStatus::Deployed)
        ->and($oldAsset->refresh()->status)->toBe(AssetStatus::InRepair);
});

it('records a replacement of a part that was never a serialized asset', function () {
    $record = maintenanceFor($this->technician, overrides: [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    // A fan or a thermal pad: a real replacement with no asset row on either
    // side, which is why both serialized columns are nullable.
    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/replacements", [
            'old_component' => HardwareComponent::factory()->create()->id,
            'new_component' => HardwareComponent::factory()->create()->id,
            'quantity' => 2,
            'reason' => 'Case fans seized',
        ])
        ->assertCreated()
        ->assertJsonPath('data.old_asset', null)
        ->assertJsonPath('data.quantity', 2);
});

it('refuses a replacement quantity of zero or less', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/replacements", ['quantity' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity');
});

it('refuses a replacement on another technician record', function () {
    $record = maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/replacements", ['quantity' => 1])
        ->assertForbidden();
});

it('audits the replacement against the maintenance record', function () {
    $record = maintenanceFor($this->technician, overrides: [
        'pc_unit_id' => $this->pc->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/replacements", ['quantity' => 1, 'reason' => 'RAM fault'])
        ->assertCreated();

    expect(ActivityLog::query()
        ->where('subject_id', $record->id)
        ->where('action', ActivityAction::MaintenanceHardwareReplaced->value)
        ->exists())->toBeTrue();
});

/* --------------------------------------------------- preventive detection */

it('reports overdue and due-soon preventive maintenance without creating any', function () {
    maintenanceFor($this->technician, 'preventive', ['scheduled_for' => now()->subDays(3)]);
    maintenanceFor($this->technician, 'preventive', ['scheduled_for' => now()->addDays(2)]);
    maintenanceFor($this->technician, 'preventive', ['scheduled_for' => now()->addDays(60)]);

    $before = MaintenanceRecord::query()->count();

    $this->artisan('maintenance:detect-due')
        ->expectsOutputToContain('1 overdue, 1 due within 7 day(s)')
        ->assertSuccessful();

    // FR-MNT-007 asks for reminders, not records. The sweep never invents work
    // nobody scheduled.
    expect(MaintenanceRecord::query()->count())->toBe($before);
});

it('honours an overridden lead time', function () {
    maintenanceFor($this->technician, 'preventive', ['scheduled_for' => now()->addDays(20)]);

    $this->artisan('maintenance:detect-due', ['--days' => 30])
        ->expectsOutputToContain('1 due within 30 day(s)')
        ->assertSuccessful();
});

it('ignores completed and undated work in the sweep', function () {
    maintenanceFor($this->technician, 'preventive', [
        'scheduled_for' => now()->subMonth(),
        'status' => MaintenanceStatus::Completed->value,
    ]);
    maintenanceFor($this->technician, 'preventive', ['scheduled_for' => null]);

    $this->artisan('maintenance:detect-due')
        ->expectsOutputToContain('0 overdue, 0 due within')
        ->assertSuccessful();
});

/* ----------------------------------------------------- evidence, in workflow */

it('attaches evidence and counts it on the record', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/evidence", [
            'file' => UploadedFile::fake()->image('before.jpg'),
            'image_type' => 'before',
            'caption' => 'PSU bulge visible',
        ])
        ->assertCreated()
        ->assertJsonPath('data.image_type', 'before')
        ->assertJsonPath('data.kind', 'image');

    $this->actingAs($this->technician)
        ->getJson("/api/maintenance/{$record->uuid}")
        ->assertOk()
        ->assertJsonPath('data.evidence_count', 1);
});

it('refuses evidence on a completed record', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);

    // The visit is closed and so is its evidence set.
    $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/evidence", [
            'file' => UploadedFile::fake()->image('after.jpg'),
            'image_type' => 'after',
        ])
        ->assertForbidden();
});

it('removes evidence and leaves the withdrawal in the audit trail', function () {
    $record = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);

    $id = $this->actingAs($this->technician)
        ->postJson("/api/maintenance/{$record->uuid}/evidence", [
            'file' => UploadedFile::fake()->image('during.jpg'),
            'image_type' => 'during',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->technician)
        ->deleteJson("/api/maintenance/{$record->uuid}/evidence/{$id}")
        ->assertOk();

    expect($record->images()->count())->toBe(0)
        ->and(ActivityLog::query()
            ->where('subject_id', $record->id)
            ->where('action', ActivityAction::MaintenanceEvidenceRemoved->value)
            ->exists())->toBeTrue();
});

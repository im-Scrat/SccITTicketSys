<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\ActivityLog;
use App\Models\MaintenanceChecklist;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\RepairImage;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WP-2.6b Stage D — proof of work from the scanned workflow
 * (SRS FR-MNT-009/010/011, FR-QR-008/013; AC-MNT-009; SDD DD-50).
 *
 * **The rule under test.** A scan says *which machine*. It says nothing about
 * whether this person may write to it, and nothing about *which job* the work
 * belongs to. Both of those are decided by the maintenance module's own rules,
 * called from here rather than restated:
 *
 *     PcUnitPolicy::viewScanned        which machine  (Stage C, unchanged)
 *     MaintenanceVisibility::canWork   which job, and may you write it
 *     MaintenanceLifecycle             whether it may be completed
 *
 * Every refusal below is made while the caller holds a perfectly valid, active
 * code and — in most cases — a perfectly valid scan identifier. That is the
 * point: possession of either contributes nothing to the decision.
 *
 * Idempotency has its own file. This one is about authorization, selection,
 * evidence and the lifecycle.
 */
beforeEach(function (): void {
    Storage::fake('local');

    /*
     * A proof submission is multipart — it carries files, so it cannot be JSON
     * — but every real client of this API is the SPA, which sends
     * `Accept: application/json`. Without the header Laravel answers a failed
     * validation with a redirect, and these tests would be asserting against a
     * response shape nothing in this system ever receives.
     */
    $this->withHeader('Accept', 'application/json');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-PROOF-01',
        'status' => PcStatus::Available->value,
    ]);

    $this->qr = QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-PROOFTEST1',
        'status' => QrStatus::Active->value,
    ]);
});

/* -------------------------------------------------------------- helpers */

/** Open maintenance for a technician on a machine. */
function proofJob(PcUnit $pcUnit, User $technician, string $status = 'in_progress', array $overrides = []): MaintenanceRecord
{
    return maintenanceFor($technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => $status,
        ...$overrides,
    ]);
}

/**
 * Scan the label as this user and return the scan's public id.
 *
 * Deliberately goes through the real endpoint rather than writing a
 * `qr_scan_logs` row directly: the identifier the submission quotes must be the
 * one the scan flow actually issues, or these tests would prove nothing about
 * the two fitting together.
 */
function proofScan(User $user, string $code = 'PC-PROOFTEST1'): string
{
    return (string) test()->actingAs($user)
        ->postJson("/api/qr/{$code}/scan")
        ->json('scan_id');
}

/** A well-formed submission payload with one photograph. */
function proofPayload(string $scanId, array $overrides = []): array
{
    return [
        'scan_id' => $scanId,
        'resolution' => 'Reseated the RAM and replaced the thermal paste.',
        'outcome' => 'in_progress',
        'evidence_type' => 'after',
        'evidence' => [UploadedFile::fake()->image('after.jpg')],
        ...$overrides,
    ];
}

/* ============================================================ happy path */

it('attaches proof to the single open record on the scanned machine', function (): void {
    $record = proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.evidence_added', 1)
        ->assertJsonPath('data.maintenance.id', $record->uuid)
        ->assertJsonPath('data.maintenance.resolution', 'Reseated the RAM and replaced the thermal paste.');

    expect(MaintenanceRecord::query()->count())->toBe(1)
        ->and($record->fresh()->images()->count())->toBe(1);
});

it('records the scan against the maintenance record', function (): void {
    // SDD DD-50: `qr_scan_logs.maintenance_record_id` is the join that makes a
    // submission traceable to the physical scan that produced it.
    $record = proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertOk();

    expect(QrScanLog::query()->where('uuid', $scanId)->value('maintenance_record_id'))
        ->toBe($record->getKey());
});

it('writes a proof-submitted audit row carrying the scan', function (): void {
    // A scan alone writes no activity row (it is an attempt). This is the point
    // at which it became a business event, and the audit trail has to be able
    // to answer "was this recorded by someone standing at the machine?".
    proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertOk();

    $log = ActivityLog::query()
        ->where('action', ActivityAction::MaintenanceProofSubmitted->value)
        ->sole();

    expect($log->user_id)->toBe($this->technician->id)
        ->and($log->properties['scan'])->toBe($scanId)
        ->and($log->properties['pc_unit'])->toBe('PC-PROOF-01');
});

it('starts a scheduled record rather than writing proof against unstarted work', function (): void {
    // Proof of work asserts work happened, so the record is moved through the
    // lifecycle — which stamps started_at and puts the machine under
    // maintenance. Writing `in_progress` directly would skip both.
    $record = proofJob($this->pcUnit, $this->technician, MaintenanceStatus::Scheduled->value);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertOk()
        ->assertJsonPath('data.maintenance.status', 'in_progress');

    $record->refresh();

    expect($record->started_at)->not->toBeNull()
        ->and($record->pc_status_before)->not->toBeNull()
        ->and($this->pcUnit->fresh()->status)->toBe(PcStatus::UnderMaintenance);
});

it('lets an administrator submit against any record on the machine', function (): void {
    // FR-QR-012: an Administrator's reach is unrestricted, and Stage D inherits
    // that from Stage C rather than deciding it again.
    $record = proofJob($this->pcUnit, $this->otherTechnician);
    $scanId = proofScan($this->admin);

    $this->actingAs($this->admin)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertOk()
        ->assertJsonPath('data.maintenance.id', $record->uuid);
});

/* ======================================================= completion gate */

it('completes the record through the lifecycle when the outcome asks for it', function (): void {
    $record = proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['outcome' => 'completed']))
        ->assertOk()
        ->assertJsonPath('data.maintenance.status', 'completed');

    expect($record->fresh()->completed_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('action', ActivityAction::MaintenanceCompleted->value)->count())->toBe(1);
});

it('is stopped by the existing checklist gate rather than a second copy of it', function (): void {
    /*
     * The completion rules are not restated in the QR workflow. This asserts
     * that by hitting one of them — FR-MNT-004's required-item enforcement —
     * from the scanned path and getting the maintenance module's own refusal.
     */
    $record = proofJob($this->pcUnit, $this->technician);

    MaintenanceChecklist::query()->create([
        'maintenance_record_id' => $record->getKey(),
        'item_label' => 'Verify the machine boots',
        'is_required' => true,
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['outcome' => 'completed']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($record->fresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('puts work on hold when the outcome asks for it', function (): void {
    $record = proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['outcome' => 'on_hold']))
        ->assertOk()
        ->assertJsonPath('data.maintenance.status', 'on_hold');

    expect($record->fresh()->status)->toBe(MaintenanceStatus::OnHold);
});

/* ============================================================== evidence */

it('refuses a submission with no evidence and writes nothing', function (): void {
    // AC-MNT-009: "a submission with no evidence is refused".
    $record = proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', [
            'scan_id' => $scanId,
            'resolution' => 'Said it was fine.',
            'outcome' => 'completed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('evidence');

    $record->refresh();

    expect($record->status)->toBe(MaintenanceStatus::InProgress)
        ->and($record->resolution)->not->toBe('Said it was fine.')
        ->and(QrScanLog::query()->where('uuid', $scanId)->value('maintenance_record_id'))->toBeNull();
});

it('never trusts the client-declared MIME type', function (): void {
    /*
     * NFR-SEC-007 through the one attachment boundary: the upload claims to be
     * a JPEG and its bytes are not, so it is refused by content inspection —
     * the same rule the ticket and asset paths apply, reached through
     * AttachmentSecurity::rules() rather than a hand-written list here.
     */
    proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    /*
     * A *real* UploadedFile, not `UploadedFile::fake()->create()`.
     *
     * The testing fake returns whatever mime type it was constructed with from
     * `getMimeType()` as well as from `getClientMimeType()` — so a faked file
     * can never disagree with its own declaration, and a test built on one
     * would pass whether the server inspected the bytes or believed the client.
     * This file's name and declared type say JPEG; its bytes say otherwise.
     */
    $path = sys_get_temp_dir().'/sccit-not-an-image.jpg';
    file_put_contents($path, 'MZ this is not a JPEG at all');

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, [
            'evidence' => [new UploadedFile($path, 'payload.jpg', 'image/jpeg', null, true)],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('evidence.0');

    expect(RepairImage::query()->count())->toBe(0);
});

it('refuses a file type outside the maintenance profile', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, [
            'evidence' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('evidence.0');
});

it('stores evidence on the private disk with a server-decided type and a checksum', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertOk();

    $image = RepairImage::query()->sole();

    expect($image->disk)->toBe('local')
        ->and($image->mime_type)->toBe('image/jpeg')
        ->and($image->original_filename)->toBe('after.jpg')
        ->and($image->checksum)->toHaveLength(64)
        ->and($image->file_size)->toBeGreaterThan(0)
        ->and($image->image_type->value)->toBe('after');

    Storage::disk('local')->assertExists($image->storage_path);
});

it('refuses an evidence stage outside before, during and after', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['evidence_type' => 'afterwards']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('evidence_type');
});

/* ============================================= selection: do not guess */

it('refuses to choose when the machine carries two jobs this technician may work', function (): void {
    // Client decision, 2026-08-29: do not guess; require explicit selection.
    $first = proofJob($this->pcUnit, $this->technician);
    $second = proofJob($this->pcUnit, $this->technician, MaintenanceStatus::Scheduled->value);
    $scanId = proofScan($this->technician);

    $response = $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');

    expect(collect($response->json('targets'))->pluck('id')->all())
        ->toEqualCanonicalizing([$first->uuid, $second->uuid]);

    // Nothing was written while the question was open.
    expect(RepairImage::query()->count())->toBe(0);
});

it('accepts the technicians explicit choice', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $chosen = proofJob($this->pcUnit, $this->technician, MaintenanceStatus::Scheduled->value);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['maintenance_id' => $chosen->uuid]))
        ->assertOk()
        ->assertJsonPath('data.maintenance.id', $chosen->uuid);
});

it('offers the chooser exactly the jobs the submission would accept', function (): void {
    // The list endpoint and the write path resolve through the same service, so
    // a job the chooser shows can never be one the write refuses.
    $mine = proofJob($this->pcUnit, $this->technician);
    proofJob($this->pcUnit, $this->otherTechnician);
    proofJob($this->pcUnit, $this->technician, MaintenanceStatus::Completed->value);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PROOFTEST1/work')
        ->assertOk()
        ->assertJsonCount(1, 'data.targets')
        ->assertJsonPath('data.targets.0.id', $mine->uuid)
        ->assertJsonPath('data.may_open_record', true);
});

/* ================================================================== IDOR */

it('refuses a maintenance record belonging to another technician', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $theirs = proofJob($this->pcUnit, $this->otherTechnician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['maintenance_id' => $theirs->uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');

    expect($theirs->fresh()->images()->count())->toBe(0);
});

it('refuses a record that exists on a different machine', function (): void {
    // The label names the machine. Proof of work on this one must not be able
    // to land on another, even a job the caller legitimately owns.
    proofJob($this->pcUnit, $this->technician);
    $elsewhere = proofJob(PcUnit::factory()->create(), $this->technician);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['maintenance_id' => $elsewhere->uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');
});

it('refuses an invented maintenance identifier the same way as a real forbidden one', function (): void {
    // Non-disclosure: "no such record" and "not yours" must be indistinguishable
    // or the endpoint enumerates records one guess at a time.
    proofJob($this->pcUnit, $this->technician);
    $theirs = proofJob($this->pcUnit, $this->otherTechnician);
    $scanId = proofScan($this->technician);

    $invented = $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, [
            'maintenance_id' => '11111111-2222-4333-8444-555555555555',
        ]))->assertStatus(422);

    $forbidden = $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['maintenance_id' => $theirs->uuid]))
        ->assertStatus(422);

    expect($invented->json('errors'))->toBe($forbidden->json('errors'));
});

it('refuses a completed record named explicitly', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $closed = proofJob($this->pcUnit, $this->technician, MaintenanceStatus::Completed->value);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['maintenance_id' => $closed->uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');
});

it('refuses a cancelled record named explicitly', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $cancelled = proofJob($this->pcUnit, $this->technician, MaintenanceStatus::Cancelled->value);
    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId, ['maintenance_id' => $cancelled->uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');
});

/* ==================================================== the scan identifier */

it('refuses another technicians scan identifier', function (): void {
    // A harvested scan id is worthless: the log row must belong to the caller.
    proofJob($this->pcUnit, $this->technician);
    proofJob($this->pcUnit, $this->otherTechnician);

    $theirScan = proofScan($this->otherTechnician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($theirScan))
        ->assertStatus(422)
        ->assertJsonValidationErrors('scan_id');
});

it('refuses a scan identifier taken from a different machine', function (): void {
    proofJob($this->pcUnit, $this->technician);

    $otherPc = PcUnit::factory()->create(['status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $otherPc->id,
        'asset_id' => null,
        'code' => 'PC-OTHERMACH1',
        'status' => QrStatus::Active->value,
    ]);
    proofJob($otherPc, $this->technician);

    $otherScan = proofScan($this->technician, 'PC-OTHERMACH1');

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($otherScan))
        ->assertStatus(422)
        ->assertJsonValidationErrors('scan_id');
});

it('refuses an anonymous scans identifier', function (): void {
    // An unauthenticated scan is logged with a null scanner and never returns a
    // scan id; even holding one, it belongs to nobody and cannot be quoted.
    proofJob($this->pcUnit, $this->technician);

    $this->postJson('/api/qr/PC-PROOFTEST1/scan')->assertOk();
    $anonymous = (string) QrScanLog::query()->whereNull('scanned_by')->value('uuid');

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($anonymous))
        ->assertStatus(422)
        ->assertJsonValidationErrors('scan_id');
});

/* ======================================================== role boundary */

it('refuses an unauthenticated submission', function (): void {
    proofJob($this->pcUnit, $this->technician);

    $this->postJson('/api/qr/PC-PROOFTEST1/proof', ['scan_id' => (string) Str::uuid()])
        ->assertUnauthorized();
});

it('refuses a teacher', function (): void {
    proofJob($this->pcUnit, $this->technician);

    $this->actingAs($this->teacher)
        ->postJson('/api/qr/PC-PROOFTEST1/proof', ['scan_id' => (string) Str::uuid()])
        ->assertForbidden();
});

it('refuses a technician with no work on the scanned machine', function (): void {
    // Stage C's boundary, inherited. The technician has maintenance somewhere,
    // just not here — and a valid label does not bridge the gap.
    proofJob(PcUnit::factory()->create(), $this->technician);
    proofJob($this->pcUnit, $this->otherTechnician);

    /*
     * A complete, valid payload — so the refusal below is the authorization
     * gate answering, not validation getting there first. The 403 comes from
     * `PcUnitPolicy::viewScanned` and is byte-identical to the one the Stage C
     * panel gives for the same machine: the two surfaces must not disagree, and
     * neither may name the equipment it just refused.
     */
    $response = $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload((string) Str::uuid()))
        ->assertForbidden();

    expect($response->getContent())->not->toContain('PC-PROOF-01');

    $panel = $this->actingAs($this->technician)->getJson('/api/qr/PC-PROOFTEST1/panel')->assertForbidden();

    expect($response->json('message'))->toBe($panel->json('message'));
});

it('refuses a user stripped of maintenance.update', function (): void {
    // Permission boundary: writing evidence is a maintenance write, and the
    // floor is the same one the module's own evidence route carries.
    proofJob($this->pcUnit, $this->technician);

    $this->technician->role->permissions()->detach(
        Permission::query()->where('name', 'maintenance.update')->value('id'),
    );
    app(PermissionResolver::class)->forget($this->technician);

    $this->actingAs($this->technician->fresh())
        ->postJson('/api/qr/PC-PROOFTEST1/proof', ['scan_id' => (string) Str::uuid()])
        ->assertForbidden();
});

/* ============================================================ the label */

it('refuses a revoked label without disclosing the machine', function (): void {
    proofJob($this->pcUnit, $this->technician);
    $scanId = proofScan($this->technician);

    $this->qr->forceFill(['status' => QrStatus::Revoked->value])->save();

    $response = $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_inactive');

    expect($response->getContent())->not->toContain('PC-PROOF-01');
});

it('refuses an unknown label', function (): void {
    proofJob($this->pcUnit, $this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-NOSUCHLABEL/proof', proofPayload((string) Str::uuid()))
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_unknown');
});

/* ====================================== FR-MNT-009: opening a record */

it('opens a corrective record when the machine has none and links the assignment', function (): void {
    /*
     * FR-MNT-009's narrow exception. The technician reached the machine through
     * an active ticket assignment, holds `maintenance.create` in their own
     * right, and there is nothing to attach to — so a record is opened and
     * linked to the ticket the work arose from. The scan conferred nothing: the
     * permission did.
     */
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pcUnit->id]);
    $ticket->assignments()->create([
        'technician_id' => $this->technician->id,
        'assigned_by' => $this->admin->id,
        'status' => AssignmentStatus::Accepted->value,
        'assigned_at' => now(),
    ]);

    $scanId = proofScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertCreated()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.maintenance.ticket', $ticket->ticket_number);

    $record = MaintenanceRecord::query()->sole();

    expect($record->pc_unit_id)->toBe($this->pcUnit->id)
        ->and($record->technician_id)->toBe($this->technician->id)
        ->and($record->images()->count())->toBe(1);
});

it('refuses and creates nothing when the caller cannot open maintenance work', function (): void {
    // AC-MNT-009's third clause, exactly: no active record they may work, no
    // permission to create one, so the submission is refused and no record is
    // created.
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pcUnit->id]);
    $ticket->assignments()->create([
        'technician_id' => $this->technician->id,
        'assigned_by' => $this->admin->id,
        'status' => AssignmentStatus::Accepted->value,
        'assigned_at' => now(),
    ]);

    $scanId = proofScan($this->technician);

    $this->technician->role->permissions()->detach(
        Permission::query()->where('name', 'maintenance.create')->value('id'),
    );
    app(PermissionResolver::class)->forget($this->technician);

    $this->actingAs($this->technician->fresh())
        ->post('/api/qr/PC-PROOFTEST1/proof', proofPayload($scanId))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');

    expect(MaintenanceRecord::query()->count())->toBe(0)
        ->and(RepairImage::query()->count())->toBe(0);
});

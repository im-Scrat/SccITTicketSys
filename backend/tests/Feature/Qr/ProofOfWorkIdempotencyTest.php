<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\ActivityLog;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\RepairImage;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WP-2.6b Stage D — duplicate prevention by construction
 * (SRS FR-MNT-012; AC-MNT-009; SDD DD-50).
 *
 * FR-MNT-012 is unusually precise about *where* the identity lives:
 *
 * > "a repeated scan of the same code, a resubmission of the same scan, or a
 * >  repeat submission against the same active record shall **update** that
 * >  record rather than create a second one; the operation shall be idempotent
 * >  **on the scan**"
 *
 * On the scan — not on the HTTP request. A scan is a single physical event; a
 * request is a network artefact that a bad signal can multiply. So the tests
 * below all take the same shape: do the thing twice, and assert that the
 * *world* looks as though it happened once.
 *
 * The layers each have a test:
 *
 *   L2  one nullable FK on `qr_scan_logs`, set once
 *   L3  the scan row taken `FOR UPDATE` for the whole transaction
 *   L4  an advisory lock on (machine, technician) around resolve-or-create
 *   L4b evidence deduplicated on (record, checksum, stage)
 *   L5  the lifecycle's existing no-op on an unchanged status
 *
 * L3 and L4 are mechanisms a single-connection test suite cannot race against
 * honestly — Laravel wraps each test in one transaction, so a second connection
 * would not see the first's uncommitted rows. Rather than fake a race, the two
 * locks are asserted *directly*, by watching the SQL the submission emits. What
 * a genuine concurrent pair would do is then covered by the sequential replay
 * tests, which exercise the same code path the loser of a race takes.
 */
beforeEach(function (): void {
    Storage::fake('local');

    // Multipart requests, answered as the SPA is answered: without an explicit
    // Accept header a failed validation redirects instead of returning 422.
    $this->withHeader('Accept', 'application/json');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->technician = userWithRole('technician');

    $this->pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-IDEM-01',
        'status' => PcStatus::Available->value,
    ]);

    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-IDEMPOTENT',
        'status' => QrStatus::Active->value,
    ]);

    $this->record = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
});

/* -------------------------------------------------------------- helpers */

function idemScan(User $user): string
{
    return (string) test()->actingAs($user)
        ->postJson('/api/qr/PC-IDEMPOTENT/scan')
        ->json('scan_id');
}

/**
 * The *same photograph* every time.
 *
 * `UploadedFile::fake()->image()` draws a new file per call, so re-uploading
 * "the same" evidence has to mean the same bytes — otherwise the deduplication
 * tests would pass for the wrong reason.
 */
function idemPhoto(string $name = 'after.jpg'): UploadedFile
{
    $path = sys_get_temp_dir().'/sccit-proof-fixture.jpg';

    if (! file_exists($path)) {
        $image = imagecreatetruecolor(64, 64);
        imagejpeg($image, $path);

    }

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

function idemPayload(string $scanId, array $overrides = []): array
{
    return [
        'scan_id' => $scanId,
        'resolution' => 'Replaced the power supply unit.',
        'outcome' => 'in_progress',
        'evidence_type' => 'after',
        'evidence' => [idemPhoto()],
        ...$overrides,
    ];
}

/* ================================================ L2 — one scan, one job */

it('gives one maintenance record when the same scan is submitted twice', function (): void {
    // AC-MNT-009: "when they submit proof of work twice for the same scan, then
    // one maintenance record exists and it was updated rather than duplicated."
    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
        ->assertOk()
        ->assertJsonPath('data.replayed', false);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, [
            'resolution' => 'Replaced the power supply unit and retested.',
        ]))
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.maintenance.id', $this->record->uuid);

    expect(MaintenanceRecord::query()->count())->toBe(1)
        ->and($this->record->fresh()->resolution)->toBe('Replaced the power supply unit and retested.');
});

it('binds the scan to a record exactly once', function (): void {
    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))->assertOk();

    $boundAt = QrScanLog::query()->where('uuid', $scanId)->value('maintenance_record_id');

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))->assertOk();

    expect(QrScanLog::query()->where('uuid', $scanId)->value('maintenance_record_id'))->toBe($boundAt);
});

it('refuses a replay that names a different record', function (): void {
    // One scan describes one job. Silently re-pointing the binding would be the
    // duplicate FR-MNT-012 exists to prevent, wearing a different hat.
    $other = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
    ]);

    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, ['maintenance_id' => $this->record->uuid]))
        ->assertOk();

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, ['maintenance_id' => $other->uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');

    expect($other->fresh()->images()->count())->toBe(0);
});

it('refuses a technicians replay once the job is finished, at the machine gate', function (): void {
    /*
     * Completing the only job on a machine ends the technician's entitlement to
     * that machine — Stage C's rule, and it bites here one layer *before* the
     * replay check: the 403 comes from `PcUnitPolicy::viewScanned`, not from
     * anything in the proof action. A held scan identifier does not survive the
     * work it was issued for.
     */
    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, ['outcome' => 'completed']))
        ->assertOk();

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
        ->assertForbidden();

    expect(RepairImage::query()->count())->toBe(1);
});

it('refuses a replay onto a completed record even for a caller who still reaches the machine', function (): void {
    /*
     * The inner guard, reached by the one caller the machine gate never stops.
     * An administrator's reach is unrestricted (FR-QR-012), so this gets all the
     * way to the action — where the binding is re-tested against
     * `MaintenanceVisibility::canWork()` and refused because the record is
     * closed. The binding is a record of what happened, never a standing
     * permission to keep writing.
     */
    $admin = userWithRole('administrator');

    $scanId = (string) $this->actingAs($admin)
        ->postJson('/api/qr/PC-IDEMPOTENT/scan')->json('scan_id');

    $this->actingAs($admin)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, ['outcome' => 'completed']))
        ->assertOk();

    $this->actingAs($admin)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
        ->assertStatus(422)
        ->assertJsonValidationErrors('scan_id');

    expect(MaintenanceRecord::query()->count())->toBe(1);
});

/* ========================================== a second scan is not a second job */

it('attaches a fresh scan of the same label to the same record', function (): void {
    // "A repeated scan of the same code ... shall update that record rather
    // than create a second one." Two distinct scans, one job.
    $first = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($first))->assertOk();

    $second = idemScan($this->technician);

    expect($second)->not->toBe($first);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($second, [
            'evidence' => [idemPhoto('second-visit.jpg')],
        ]))
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.maintenance.id', $this->record->uuid);

    expect(MaintenanceRecord::query()->count())->toBe(1);
});

/* ================================================== L4b — evidence dedupe */

it('does not store the same photograph twice in the same stage', function (): void {
    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
        ->assertOk()
        ->assertJsonPath('data.evidence_added', 1)
        ->assertJsonPath('data.evidence_skipped', 0);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
        ->assertOk()
        ->assertJsonPath('data.evidence_added', 0)
        ->assertJsonPath('data.evidence_skipped', 1);

    expect(RepairImage::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('keeps the same photograph filed under a different stage', function (): void {
    // Deduplication is keyed on the stage as well as the bytes: the same image
    // filed as `before` and again as `after` is two deliberate assertions, and
    // collapsing them would discard a technician's meaning, not a duplicate.
    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, ['evidence_type' => 'before']))
        ->assertOk();

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId, ['evidence_type' => 'after']))
        ->assertOk()
        ->assertJsonPath('data.evidence_added', 1);

    expect(RepairImage::query()->count())->toBe(2);
});

it('accepts a retry that carries no files because the evidence is already there', function (): void {
    // The network-retry case. FR-MNT-010 is asserted on the record, not on the
    // request, so a resubmission whose upload already landed still succeeds.
    $scanId = idemScan($this->technician);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))->assertOk();

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', [
            'scan_id' => $scanId,
            'resolution' => 'Replaced the power supply unit.',
            'outcome' => 'completed',
        ])
        ->assertOk()
        ->assertJsonPath('data.maintenance.status', 'completed');

    expect(RepairImage::query()->count())->toBe(1);
});

/* ============================================ L5 — no spurious audit rows */

it('writes one work-started audit row however often the outcome is resubmitted', function (): void {
    /*
     * `MaintenanceLifecycle::transition()` returns early when the record is
     * already in the requested status, so the replay adds no second "Work
     * started" to the timeline. Asserted here rather than trusted, because a
     * timeline that says a job began three times is a timeline nobody can use.
     */
    $this->record->forceFill(['status' => MaintenanceStatus::Scheduled->value])->save();

    $scanId = idemScan($this->technician);

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($this->technician)
            ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
            ->assertOk();
    }

    expect(ActivityLog::query()->where('action', ActivityAction::MaintenanceStarted->value)->count())->toBe(1)
        ->and(MaintenanceRecord::query()->count())->toBe(1)
        ->and(RepairImage::query()->count())->toBe(1);
});

/* ================================================= L3 and L4 — the locks */

it('takes a row lock on the scan and an advisory lock on the workspace', function (): void {
    /*
     * The mechanisms, asserted directly.
     *
     * A single-connection test suite cannot honestly race two submissions: each
     * test runs inside one transaction, so a second connection would not see
     * uncommitted rows and the "race" would be theatre. What *can* be proven is
     * that the submission actually takes the two locks a real race would need —
     * which is the part that would silently rot if someone removed it.
     */
    $scanId = idemScan($this->technician);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-IDEMPOTENT/proof', idemPayload($scanId))
        ->assertOk();

    $joined = implode("\n", $statements);

    expect($joined)->toContain('pg_advisory_xact_lock')
        ->and(collect($statements)->contains(
            fn (string $sql): bool => str_contains($sql, 'qr_scan_logs') && str_contains($sql, 'for update'),
        ))->toBeTrue();
});

it('resolves the target under the same transaction that writes the binding', function (): void {
    /*
     * The property the locks exist to protect: resolve-or-create and the write
     * of `maintenance_record_id` are one atomic step. If the submission fails
     * after resolving — here, on the evidence gate — nothing survives: no
     * record, no binding, no evidence.
     */
    $emptyPc = PcUnit::factory()->create(['status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $emptyPc->id,
        'asset_id' => null,
        'code' => 'PC-ATOMICTEST',
        'status' => QrStatus::Active->value,
    ]);

    // Reachable through a ticket rather than maintenance, so the resolve step
    // takes the create branch and then hits the evidence gate.
    $ticket = ticketFor(userWithRole('teacher'), 'open', ['pc_unit_id' => $emptyPc->id]);
    $ticket->assignments()->create([
        'technician_id' => $this->technician->id,
        'assigned_by' => userWithRole('administrator')->id,
        'status' => AssignmentStatus::Accepted->value,
        'assigned_at' => now(),
    ]);

    $scanId = (string) $this->actingAs($this->technician)
        ->postJson('/api/qr/PC-ATOMICTEST/scan')->json('scan_id');

    $before = MaintenanceRecord::query()->count();

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-ATOMICTEST/proof', [
            'scan_id' => $scanId,
            'resolution' => 'Swapped the keyboard.',
            'outcome' => 'in_progress',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('evidence');

    expect(MaintenanceRecord::query()->count())->toBe($before)
        ->and(QrScanLog::query()->where('uuid', $scanId)->value('maintenance_record_id'))->toBeNull();
});

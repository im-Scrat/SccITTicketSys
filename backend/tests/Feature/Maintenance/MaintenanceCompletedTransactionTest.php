<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Events\PcStatusChanged;
use App\Domains\Maintenance\Events\MaintenanceCompleted;
use App\Domains\Maintenance\Services\MaintenanceLifecycle;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\RepairImage;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * WP-K — the transaction boundary of MaintenanceCompleted (and PcStatusChanged).
 *
 * **What these tests can and cannot see.** RefreshDatabase wraps every test in
 * its own transaction, and the installed testing transaction manager
 * (`Foundation\Testing\DatabaseTransactionsManager`) excludes that wrapper from
 * after-commit scope: callbacks fire when the *application's* outermost
 * transaction returns to level 1. So a listener here genuinely observes the
 * production ordering — nothing before the root commit, once after it, never
 * after a rollback — rather than a harness artefact. Each observation records
 * `DB::transactionLevel()` at the moment the listener ran (1 = no application
 * transaction open) and the record's status as a fresh query reads it.
 *
 * Real listeners, never Event::fake(): a fake records the dispatch call itself,
 * which is exactly the moment this suite needs to prove is *not* when
 * listeners run.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->withHeader('Accept', 'application/json');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->technician = userWithRole('technician');

    // In a room, so PcStatusChanged (which needs a room to broadcast on) fires.
    $this->pcUnit = PcUnit::factory()->create([
        'room_id' => Room::factory()->create()->id,
        'unit_code' => 'PC-WPK-01',
        'status' => PcStatus::Online->value,
    ]);

    $this->qr = QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-WPKTEST01',
        'status' => QrStatus::Active->value,
    ]);
});

/**
 * Record, for every dispatch of `$event` that reaches listeners, the
 * transaction level and the maintenance record's committed status.
 *
 * @return ArrayObject<int, array{level: int, status: string|null}>
 */
function observeAfterCommit(string $event, ?MaintenanceRecord $record = null): ArrayObject
{
    $seen = new ArrayObject;

    Event::listen($event, function (object $payload) use ($seen, $record): void {
        $id = $payload instanceof MaintenanceCompleted ? $payload->record->id : $record?->id;

        // value() applies the model's enum cast; compare on the stored string.
        $status = $id !== null ? MaintenanceRecord::query()->whereKey($id)->value('status') : null;

        $seen[] = [
            'level' => DB::transactionLevel(),
            'status' => $status instanceof BackedEnum ? $status->value : $status,
        ];
    });

    return $seen;
}

/** An in-progress corrective record on the WP-K machine, with evidence, ready to complete. */
function wpkReadyRecord(PcUnit $pcUnit, User $technician): MaintenanceRecord
{
    $record = maintenanceFor($technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    RepairImage::factory()->create(['maintenance_record_id' => $record->id]);

    return $record;
}

function wpkScan(User $user): string
{
    return (string) test()->actingAs($user)->postJson('/api/qr/PC-WPKTEST01/scan')->json('scan_id');
}

function wpkProof(string $scanId, string $outcome): array
{
    return [
        'scan_id' => $scanId,
        'resolution' => 'Replaced the failed PSU; machine boots and holds load.',
        'outcome' => $outcome,
        'evidence_type' => 'after',
        'evidence' => [UploadedFile::fake()->image('after.jpg')],
    ];
}

/* ================================================ 1. normal completion path */

it('fires MaintenanceCompleted once, after the commit, on the ordinary status endpoint', function (): void {
    $record = wpkReadyRecord($this->pcUnit, $this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}/status", ['status' => 'completed', 'resolution' => 'Replaced the PSU.'])
        ->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['level'])->toBe(1)
        ->and($seen[0]['status'])->toBe('completed');
});

it('holds the event while an enclosing transaction is open, and releases it once on commit', function (): void {
    $record = wpkReadyRecord($this->pcUnit, $this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    DB::transaction(function () use ($record, $seen): void {
        app(MaintenanceLifecycle::class)->transition(
            $record, MaintenanceStatus::Completed, $this->technician, ['resolution' => 'Replaced the PSU.'],
        );

        // The lifecycle's own transaction has "finished" — but it was a savepoint.
        expect($seen)->toHaveCount(0);
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['level'])->toBe(1)
        ->and($seen[0]['status'])->toBe('completed');
});

it('never delivers the event for a completion that rolls back', function (): void {
    $record = wpkReadyRecord($this->pcUnit, $this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    expect(fn () => DB::transaction(function () use ($record): void {
        app(MaintenanceLifecycle::class)->transition(
            $record, MaintenanceStatus::Completed, $this->technician, ['resolution' => 'Replaced the PSU.'],
        );

        throw new RuntimeException('something after completion failed');
    }))->toThrow(RuntimeException::class);

    expect($seen)->toHaveCount(0)
        ->and($record->fresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('does not fire again for a no-op re-submission of completed', function (): void {
    $record = wpkReadyRecord($this->pcUnit, $this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    $lifecycle = app(MaintenanceLifecycle::class);
    $lifecycle->transition($record, MaintenanceStatus::Completed, $this->technician, ['resolution' => 'Done.']);
    $lifecycle->transition($record->fresh(), MaintenanceStatus::Completed, $this->technician, ['resolution' => 'Done.']);

    expect($seen)->toHaveCount(1);
});

/* ========================================= 2. nested SubmitProofOfWork path */

it('fires once, after the outer SubmitProofOfWork transaction commits', function (): void {
    wpkReadyRecord($this->pcUnit, $this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-WPKTEST01/proof', wpkProof(wpkScan($this->technician), 'completed'))
        ->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['level'])->toBe(1)
        ->and($seen[0]['status'])->toBe('completed');
});

it('never delivers the event when SubmitProofOfWork fails after the completion step', function (): void {
    $record = wpkReadyRecord($this->pcUnit, $this->technician);
    $scanId = wpkScan($this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    // The scan binding is written after applyWork() has completed the record,
    // still inside SubmitProofOfWork's own transaction. Failing it there is the
    // case the WP-K mandate names: a completion that happened, then unhappened.
    QrScanLog::saving(function (): void {
        throw new RuntimeException('scan binding failed');
    });

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-WPKTEST01/proof', wpkProof($scanId, 'completed'))
        ->assertStatus(500);

    expect($seen)->toHaveCount(0)
        ->and($record->fresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('does not fire for a proof-of-work submission that leaves the work in progress', function (): void {
    wpkReadyRecord($this->pcUnit, $this->technician);
    $seen = observeAfterCommit(MaintenanceCompleted::class);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-WPKTEST01/proof', wpkProof(wpkScan($this->technician), 'in_progress'))
        ->assertOk();

    expect($seen)->toHaveCount(0);
});

/* ==================== PcStatusChanged — the same boundary, found by WP-K */

it('broadcasts the machine status change only after the outer proof-of-work commit', function (): void {
    // Starts `scheduled`, so SubmitProofOfWork moves the machine twice
    // (start -> under_maintenance, complete -> restored), both nested.
    $record = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
    ]);
    $seen = observeAfterCommit(PcStatusChanged::class, $record);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-WPKTEST01/proof', wpkProof(wpkScan($this->technician), 'completed'))
        ->assertOk();

    expect($seen)->not->toBeEmpty();
    foreach ($seen as $observation) {
        // Every broadcast ran with no application transaction open, and saw
        // the final committed state rather than the intermediate one.
        expect($observation['level'])->toBe(1)
            ->and($observation['status'])->toBe('completed');
    }
});

it('never broadcasts a machine status change that rolled back', function (): void {
    $record = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
    ]);
    $scanId = wpkScan($this->technician);
    $seen = observeAfterCommit(PcStatusChanged::class, $record);

    QrScanLog::saving(function (): void {
        throw new RuntimeException('scan binding failed');
    });

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-WPKTEST01/proof', wpkProof($scanId, 'completed'))
        ->assertStatus(500);

    expect($seen)->toHaveCount(0)
        ->and($this->pcUnit->fresh()->status)->toBe(PcStatus::Online);
});

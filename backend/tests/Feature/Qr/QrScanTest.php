<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Enums\ScanResult;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;
use Database\Seeders\MaintenanceTypeSeeder;

/**
 * WP-2.6b Stage B — scan resolution and classification
 * (SRS FR-QR-005/006/009/013; SDD §24, DD-47/DD-48).
 *
 * The classification is what reaches `qr_scan_logs`; what reaches the *caller*
 * is a separate, coarser decision tested in {@see QrScanLoggingTest}. These two
 * concerns are tested apart because collapsing them is exactly the mistake that
 * turns a scan log into an enumeration oracle.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);

    $this->technician = userWithRole('technician');
    $this->pcUnit = PcUnit::factory()->create(['status' => PcStatus::Available->value]);
    $this->qr = QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-STAGEBTEST',
        'status' => QrStatus::Active->value,
    ]);

    /*
     * Reachability, not just entitlement (Stage C, FR-QR-012).
     *
     * These tests are about *classification and logging*, so the technician is
     * given genuine open work on the machine — otherwise every "success" case
     * below would be refused by `PcUnitPolicy::viewScanned` and the file would
     * be testing authorization by accident. Whether the refusal is correct is
     * QrPanelAuthorizationTest's job.
     */
    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
});

/** The scan endpoint, as the SPA calls it. */
function scan(string $code, array $body = [])
{
    return test()->postJson("/api/qr/{$code}/scan", $body);
}

/* ------------------------------------------------------- classification */

it('classifies an active code on a live pc unit as success', function (): void {
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST')->assertOk()->assertJsonPath('next', 'panel');

    expect(QrScanLog::query()->latest('id')->first()->scan_result)
        ->toBe(ScanResult::Success);
});

it('classifies an unknown code as invalid', function (): void {
    $this->actingAs($this->technician);

    scan('PC-NOTAREALCODE')->assertForbidden();

    $log = QrScanLog::query()->latest('id')->first();

    expect($log->scan_result)->toBe(ScanResult::Invalid)
        ->and($log->qr_code_id)->toBeNull()
        ->and($log->pc_unit_id)->toBeNull();
});

it('classifies a revoked or inactive code as expired', function (string $status): void {
    $this->qr->forceFill(['status' => $status])->save();
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST')->assertForbidden();

    expect(QrScanLog::query()->latest('id')->first()->scan_result)
        ->toBe(ScanResult::Expired);
})->with([QrStatus::Revoked->value, QrStatus::Inactive->value]);

it('classifies an active code on a retired pc unit as expired', function (): void {
    // FR-QR-006: `expired` is behavioural — the QR *or* the target may be the
    // thing that is no longer live (OI-04: no date field).
    $this->pcUnit->forceFill(['status' => PcStatus::Retired->value])->save();
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST')->assertForbidden();

    expect(QrScanLog::query()->latest('id')->first()->scan_result)
        ->toBe(ScanResult::Expired);
});

it('classifies an active code on an archived pc unit as expired', function (): void {
    // FR-QR-012 excludes archived rows from the panel outright, so a scan that
    // resolves to one has nothing legitimate to show.
    $this->pcUnit->delete();
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST')->assertForbidden();

    expect(QrScanLog::query()->latest('id')->first()->scan_result)
        ->toBe(ScanResult::Expired);
});

it('classifies an active code on a disposed asset as expired', function (): void {
    $asset = Asset::factory()->create(['status' => AssetStatus::Disposed->value]);
    QrCode::factory()->create([
        'pc_unit_id' => null,
        'asset_id' => $asset->id,
        'code' => 'AS-DISPOSEDXX',
        'status' => QrStatus::Active->value,
    ]);

    $this->actingAs($this->technician);

    scan('AS-DISPOSEDXX')->assertForbidden();

    expect(QrScanLog::query()->latest('id')->first()->scan_result)
        ->toBe(ScanResult::Expired);
});

it('never produces a mismatch classification', function (): void {
    /*
     * FR-QR-006 defines `mismatch` against a *claimed* target, and DD-48 forbids
     * the caller supplying one — the URL carries the code and nothing else. The
     * case stays reserved rather than being made reachable by inventing a claim
     * parameter (approved decision OD-6). This test is the guard that nobody
     * quietly adds one.
     */
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST');
    scan('PC-NOTAREALCODE');
    $this->qr->forceFill(['status' => QrStatus::Revoked->value])->save();
    scan('PC-STAGEBTEST');

    expect(QrScanLog::query()->where('scan_result', ScanResult::Mismatch->value)->count())
        ->toBe(0)
        ->and(QrScanLog::query()->count())->toBe(3);
});

/* ------------------------------------------------------- resolution rules */

it('resolves an asset label but offers no workflow for it', function (): void {
    // Assets carry labels (FR-QR-001), but WP-2.6b's panel is the technician's
    // PC job. The scan still resolves and logs `success`; it just has nowhere
    // to send the caller.
    $asset = Asset::factory()->create(['status' => AssetStatus::Deployed->value]);
    QrCode::factory()->create([
        'pc_unit_id' => null,
        'asset_id' => $asset->id,
        'code' => 'AS-LIVEASSET1',
        'status' => QrStatus::Active->value,
    ]);

    $this->actingAs($this->technician);

    scan('AS-LIVEASSET1')
        ->assertForbidden()
        ->assertJsonPath('reason', 'no_workflow');

    $log = QrScanLog::query()->latest('id')->first();

    expect($log->scan_result)->toBe(ScanResult::Success)
        ->and($log->asset_id)->toBe($asset->id)
        ->and($log->pc_unit_id)->toBeNull();
});

it('refuses a code whose shape could not have been issued', function (string $code): void {
    // The route pattern is the first defence; a value it rejects never reaches
    // the controller, so there is nothing to log.
    $this->actingAs($this->technician);

    test()->postJson("/api/qr/{$code}/scan")->assertNotFound();

    expect(QrScanLog::query()->count())->toBe(0);
})->with([
    'slash' => 'PC%2FETC',
    'dots' => '..',
    'too long' => 'PC-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
]);

/* --------------------------------------------------------- last_scanned_at */

it('stamps last_scanned_at on a successful scan', function (): void {
    expect($this->qr->last_scanned_at)->toBeNull();

    $this->actingAs($this->technician);
    scan('PC-STAGEBTEST')->assertOk();

    expect($this->qr->fresh()->last_scanned_at)->not->toBeNull();
});

it('stamps last_scanned_at even when the label is revoked', function (): void {
    /*
     * A revoked sticker that is still being scanned is a fact an administrator
     * needs: it means the old label is still on the machine. Stamping only
     * successes would make exactly that situation invisible (FR-QR-004).
     */
    $this->qr->forceFill(['status' => QrStatus::Revoked->value])->save();

    $this->actingAs($this->technician);
    scan('PC-STAGEBTEST')->assertForbidden();

    expect($this->qr->fresh()->last_scanned_at)->not->toBeNull();
});

/* ------------------------------------------------------------ idempotency */

it('issues a distinct scan id for every scan', function (): void {
    // A scan is a physical event, so repeating one is a *new* event with its own
    // identity. Idempotency binds a submission to one scan (FR-MNT-012); it does
    // not collapse two scans into one.
    $this->actingAs($this->technician);

    $first = scan('PC-STAGEBTEST')->assertOk()->json('scan_id');
    $second = scan('PC-STAGEBTEST')->assertOk()->json('scan_id');

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first)->not->toBe($second)
        ->and(QrScanLog::query()->count())->toBe(2);
});

it('returns the scan id and the server-vouched code, and nothing about the machine', function (): void {
    $this->actingAs($this->technician);

    $payload = scan('PC-STAGEBTEST')->assertOk()->json();

    // The whole response, asserted as a set: a scan says where to go next, not
    // what is there (FR-QR-010, DD-49).
    expect(array_keys($payload))->toEqualCanonicalizing(['next', 'scan_id', 'code'])
        ->and($payload['code'])->toBe('PC-STAGEBTEST');
});

/* ------------------------------------------------------------ geolocation */

it('records optional coordinates supplied by the scanner', function (): void {
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST', ['latitude' => 14.5995, 'longitude' => 120.9842])->assertOk();

    $log = QrScanLog::query()->latest('id')->first();

    expect((float) $log->latitude)->toBe(14.5995)
        ->and((float) $log->longitude)->toBe(120.9842);
});

it('refuses coordinates outside the range the database would accept', function (array $body): void {
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST', $body)->assertStatus(422);
})->with([
    'latitude high' => [['latitude' => 91, 'longitude' => 0]],
    'latitude low' => [['latitude' => -91, 'longitude' => 0]],
    'longitude high' => [['latitude' => 0, 'longitude' => 181]],
    'longitude low' => [['latitude' => 0, 'longitude' => -181]],
]);

it('ignores half a coordinate fix rather than storing it', function (): void {
    $this->actingAs($this->technician);

    scan('PC-STAGEBTEST', ['latitude' => 14.5995])->assertOk();

    $log = QrScanLog::query()->latest('id')->first();

    expect($log->latitude)->toBeNull()
        ->and($log->longitude)->toBeNull();
});

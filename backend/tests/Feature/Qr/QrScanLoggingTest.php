<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Enums\ScanResult;
use App\Models\ActivityLog;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;
use Database\Seeders\MaintenanceTypeSeeder;

/**
 * WP-2.6b Stage B — the scan log, and the non-disclosure rule that sits on top
 * of it (SRS FR-QR-005/010/013; AC-QR-010; SDD §35.4, DD-47).
 *
 * The single most important property in this file: **the log is precise and the
 * response is not**. `qr_scan_logs.scan_result` distinguishes `success`,
 * `invalid` and `expired` exactly; the HTTP response to an unauthenticated
 * caller distinguishes none of them. If those two ever collapse into one value,
 * the endpoint becomes an oracle that answers "does this code exist?" to anyone
 * with a phone.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);

    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->admin = userWithRole('administrator');

    $this->pcUnit = PcUnit::factory()->create(['status' => PcStatus::Available->value]);
    $this->qr = QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-LOGGINGTST',
        'status' => QrStatus::Active->value,
    ]);

    // Genuine open work, so the technician clears Stage C's reachability rule
    // and these tests stay about *logging* rather than authorization.
    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
});

/* ------------------------------------------------- every attempt is logged */

it('logs an anonymous scan with a null scanner', function (): void {
    // AC-QR-010, in one assertion: the attempt is recorded, and the record says
    // nobody was signed in.
    $this->postJson('/api/qr/PC-LOGGINGTST/scan')
        ->assertOk()
        ->assertExactJson(['next' => 'sign-in']);

    $log = QrScanLog::query()->sole();

    expect($log->scanned_by)->toBeNull()
        ->and($log->scan_result)->toBe(ScanResult::Success)
        ->and($log->pc_unit_id)->toBe($this->pcUnit->id)
        ->and($log->scanned_at)->not->toBeNull()
        ->and($log->uuid)->not->toBeNull();
});

it('logs the scanner when there is a session', function (): void {
    $this->actingAs($this->technician)->postJson('/api/qr/PC-LOGGINGTST/scan')->assertOk();

    expect(QrScanLog::query()->sole()->scanned_by)->toBe($this->technician->id);
});

it('logs an anonymous scan of an unknown code', function (): void {
    $this->postJson('/api/qr/PC-NOSUCHCODE1/scan')->assertOk();

    $log = QrScanLog::query()->sole();

    expect($log->scan_result)->toBe(ScanResult::Invalid)
        ->and($log->scanned_by)->toBeNull()
        ->and($log->qr_code_id)->toBeNull();
});

it('logs a scan that was refused for lack of entitlement', function (): void {
    // A refused attempt is exactly the attempt worth being able to find later,
    // so the log is written before authorization is even considered (DD-47).
    $this->actingAs($this->teacher)->postJson('/api/qr/PC-LOGGINGTST/scan')
        ->assertForbidden();

    $log = QrScanLog::query()->sole();

    expect($log->scanned_by)->toBe($this->teacher->id)
        ->and($log->scan_result)->toBe(ScanResult::Success);
});

it('records the client address and agent', function (): void {
    $this->withHeader('User-Agent', 'SccIT-Test-Scanner/1.0')
        ->postJson('/api/qr/PC-LOGGINGTST/scan')
        ->assertOk();

    $log = QrScanLog::query()->sole();

    expect($log->ip_address)->not->toBeNull()
        ->and($log->user_agent)->toBe('SccIT-Test-Scanner/1.0');
});

it('logs every repeat of the same scan', function (): void {
    // Duplicate scans are logged, not deduplicated — that is what makes an
    // enumeration sweep visible as a burst (FR-QR-013).
    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/qr/PC-LOGGINGTST/scan')->assertOk();
    }

    expect(QrScanLog::query()->count())->toBe(3);
});

/* --------------------------------------------- the response discloses nothing */

it('answers an unauthenticated caller identically whatever the code turned out to be', function (): void {
    /*
     * The heart of FR-QR-013. Three genuinely different classifications —
     * success, invalid, expired — must produce one indistinguishable answer.
     * Asserted with `assertExactJson` so an added field cannot smuggle a
     * difference back in later.
     */
    $revoked = QrCode::factory()->create([
        'pc_unit_id' => PcUnit::factory()->create()->id,
        'asset_id' => null,
        'code' => 'PC-REVOKEDONE',
        'status' => QrStatus::Revoked->value,
    ]);

    $expected = ['next' => 'sign-in'];

    $this->postJson('/api/qr/PC-LOGGINGTST/scan')->assertOk()->assertExactJson($expected);
    $this->postJson('/api/qr/PC-NOSUCHCODE1/scan')->assertOk()->assertExactJson($expected);
    $this->postJson("/api/qr/{$revoked->code}/scan")->assertOk()->assertExactJson($expected);

    // ...while the log told the truth about all three.
    expect(QrScanLog::query()->pluck('scan_result')->map->value->all())
        ->toEqualCanonicalizing(['success', 'invalid', 'expired']);
});

it('discloses nothing about the machine to an unauthenticated caller', function (): void {
    $response = $this->postJson('/api/qr/PC-LOGGINGTST/scan')->assertOk();

    // Asserted against the encoded payload, per CLAUDE.md §6: a field cannot
    // leak back in through a later resource change without failing here.
    $body = $response->getContent();

    foreach ([
        $this->pcUnit->pc_name,
        $this->pcUnit->unit_code,
        $this->pcUnit->serial_number,
        $this->pcUnit->hostname,
        $this->pcUnit->asset_tag,
        'PC-LOGGINGTST',
        'available',
    ] as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
});

it('gives a teacher one flat refusal that does not reveal whether the code exists', function (): void {
    // An account with no maintenance entitlement must not be able to tell a real
    // label from an invented one — otherwise the oracle just costs one login.
    $real = $this->actingAs($this->teacher)->postJson('/api/qr/PC-LOGGINGTST/scan')->assertForbidden();
    $fake = $this->actingAs($this->teacher)->postJson('/api/qr/PC-NOSUCHCODE1/scan')->assertForbidden();

    expect($real->json())->toBe($fake->json())
        ->and($real->json('reason'))->toBe('not_authorized');
});

it('tells an entitled technician why a label failed', function (): void {
    // The other side of the same coin: staff doing the work get an actionable
    // reason, because "fetch a new sticker" and "this is not our label" are
    // different actions.
    $this->qr->forceFill(['status' => QrStatus::Revoked->value])->save();

    $this->actingAs($this->technician)->postJson('/api/qr/PC-LOGGINGTST/scan')
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_inactive');

    $this->actingAs($this->technician)->postJson('/api/qr/PC-NOSUCHCODE1/scan')
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_unknown');
});

it('lets an administrator proceed', function (): void {
    $this->actingAs($this->admin)->postJson('/api/qr/PC-LOGGINGTST/scan')
        ->assertOk()
        ->assertJsonPath('next', 'panel');
});

it('does not write an activity log row for a scan', function (): void {
    /*
     * A scan is an attempt, not an actor-initiated business event, and it
     * happens with no actor at all more often than not. `qr_scan_logs` is its
     * home; `activity_logs` stays for the business events (proof of work, a
     * support-request decision). The split mirrors `login_history`.
     */
    $before = ActivityLog::query()->count();

    $this->actingAs($this->technician)->postJson('/api/qr/PC-LOGGINGTST/scan')->assertOk();

    expect(ActivityLog::query()->count())->toBe($before);
});

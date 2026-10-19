<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;
use Database\Seeders\MaintenanceTypeSeeder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * WP-2.6b Stage B — enumeration resistance on the scan endpoint
 * (SRS FR-QR-013, NFR-SEC-009).
 *
 * FR-QR-013 requires the endpoint to be limited "per client **and** per
 * account", which is why `qr-scan` is the only limiter in this application that
 * returns two `Limit`s. Both are asserted here, because a limiter that silently
 * degraded to one of them would still pass a naive "does throttling work?" test.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);

    RateLimiter::clear('qr-scan');

    $this->technician = userWithRole('technician');
    $this->pcUnit = PcUnit::factory()->create(['status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-RATELIMITX',
        'status' => QrStatus::Active->value,
    ]);

    // Open work on the machine, so a permitted scan returns 200 and the tests
    // below measure the throttle rather than Stage C's reachability rule.
    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
});

afterEach(function (): void {
    RateLimiter::clear('qr-scan');
});

it('throttles an anonymous enumeration sweep by client address', function (): void {
    config()->set('security.rate_limits.qr_scan_per_minute', 5);

    // The sweep an attacker would actually run: distinct guessed codes, no
    // session, as fast as the network allows.
    foreach (range(1, 5) as $n) {
        $this->postJson("/api/qr/PC-GUESS{$n}00000/scan")->assertOk();
    }

    $this->postJson('/api/qr/PC-GUESS600000/scan')->assertStatus(429);
});

it('does not let signing in lift the client ceiling', function (): void {
    /*
     * The per-IP limit is keyed on the address alone, not "user, falling back to
     * IP". If it were the latter, an attacker would simply log in to get a fresh
     * budget — and the anonymous ceiling that resists enumeration would be
     * optional.
     */
    config()->set('security.rate_limits.qr_scan_per_minute', 3);
    config()->set('security.rate_limits.qr_scan_per_user_per_minute', 100);

    foreach (range(1, 3) as $n) {
        $this->postJson("/api/qr/PC-GUESS{$n}00000/scan")->assertOk();
    }

    $this->actingAs($this->technician)
        ->postJson('/api/qr/PC-RATELIMITX/scan')
        ->assertStatus(429);
});

it('throttles a signed-in account below the client ceiling', function (): void {
    // The per-account limit has to bite on its own, or a shared NAT address
    // would be the only thing standing between one account and a sweep.
    config()->set('security.rate_limits.qr_scan_per_minute', 100);
    config()->set('security.rate_limits.qr_scan_per_user_per_minute', 4);

    $this->actingAs($this->technician);

    foreach (range(1, 4) as $ignored) {
        $this->postJson('/api/qr/PC-RATELIMITX/scan')->assertOk();
    }

    $this->postJson('/api/qr/PC-RATELIMITX/scan')->assertStatus(429);
});

it('does not log a throttled attempt', function (): void {
    /*
     * The throttle runs ahead of the controller, so a rejected burst never
     * reaches `RecordQrScan`. That is the intended trade: FR-QR-005's "record
     * every scan attempt" covers attempts the application processes, and
     * logging a rejected flood would turn an enumeration attempt into a way to
     * fill the audit table.
     */
    config()->set('security.rate_limits.qr_scan_per_minute', 2);

    $this->postJson('/api/qr/PC-RATELIMITX/scan')->assertOk();
    $this->postJson('/api/qr/PC-RATELIMITX/scan')->assertOk();
    $this->postJson('/api/qr/PC-RATELIMITX/scan')->assertStatus(429);

    expect(QrScanLog::query()->count())->toBe(2);
});

it('lets ordinary scanning through', function (): void {
    // A technician walking a lab scans a machine every minute or two. The
    // ceilings must not be something normal work notices.
    $this->actingAs($this->technician);

    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/qr/PC-RATELIMITX/scan')->assertOk();
    }

    expect(QrScanLog::query()->count())->toBe(10);
});

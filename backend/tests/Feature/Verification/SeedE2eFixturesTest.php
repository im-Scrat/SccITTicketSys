<?php

declare(strict_types=1);

use App\Console\Commands\SeedE2eFixtures;
use App\Enums\QrStatus;
use App\Enums\UserStatus;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Command\Command;

/**
 * The fixture command behind the browser and accessibility suites (WP-2.7d).
 *
 * Two things are worth testing about a test fixture, and they are the two that
 * would hurt if they broke silently:
 *
 *   1. It refuses to run in production. The production image bakes the whole
 *      `app/` tree, so this command ships inside it. It creates accounts with a
 *      known, shared, documented password — on a production target that is a
 *      back door. The guard is the only thing standing between the two, so it
 *      gets a test of its own rather than trust.
 *
 *   2. It is idempotent. The E2E runner re-seeds before every run; a command
 *      that appended instead of updating would grow the dataset all week and
 *      turn "the suite got slower" into a mystery.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
});

it('refuses to run under APP_ENV=production and creates nothing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('sccit:e2e-fixtures')->assertExitCode(Command::FAILURE);

    expect(User::query()->where('email', 'like', 'e2e.%@sccit.test')->count())->toBe(0)
        ->and(QrCode::query()->where('code', SeedE2eFixtures::QR_CODE)->exists())->toBeFalse();
});

it('creates one active, verified account per role', function (): void {
    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    $emails = [
        'administrator' => SeedE2eFixtures::ADMIN_EMAIL,
        'technician' => SeedE2eFixtures::TECHNICIAN_EMAIL,
        'teacher' => SeedE2eFixtures::TEACHER_EMAIL,
    ];

    foreach ($emails as $roleSlug => $email) {
        $user = User::query()->with('role')->where('email', $email)->first();

        expect($user)->not->toBeNull()
            ->and($user->role->slug)->toBe($roleSlug)
            ->and($user->status)->toBe(UserStatus::Active)
            ->and($user->email_verified_at)->not->toBeNull()
            // Hashed, never stored in the clear — the seeder assigns plaintext
            // and relies on the model's 'hashed' cast to do the work.
            ->and(Hash::check('E2ePassw0rd!23', $user->password))->toBeTrue();
    }
});

it('binds the fixture label to exactly one active target', function (): void {
    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    $qr = QrCode::query()->where('code', SeedE2eFixtures::QR_CODE)->firstOrFail();
    $pcUnit = PcUnit::query()->where('unit_code', 'E2E-PC-001')->firstOrFail();

    expect($qr->status)->toBe(QrStatus::Active)
        ->and($qr->pc_unit_id)->toBe($pcUnit->id)
        // The qr_codes_target_check constraint: exactly one of the two targets.
        ->and($qr->asset_id)->toBeNull()
        // FR-QR-002's denormalized convenience copy stays in step.
        ->and($pcUnit->qr_identifier)->toBe(SeedE2eFixtures::QR_CODE);
});

it('gives the fixture technician live work on the scanned machine', function (): void {
    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    $technician = User::query()->where('email', SeedE2eFixtures::TECHNICIAN_EMAIL)->firstOrFail();
    $pcUnit = PcUnit::query()->where('unit_code', 'E2E-PC-001')->firstOrFail();
    $record = MaintenanceRecord::query()->where('title', 'E2E fixture — scanned job')->firstOrFail();

    expect($record->technician_id)->toBe($technician->id)
        ->and($record->pc_unit_id)->toBe($pcUnit->id)
        ->and($record->status->value)->toBe('in_progress');
});

it('is idempotent — a second run updates rather than duplicates', function (): void {
    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    $firstRecordId = MaintenanceRecord::query()->where('title', 'E2E fixture — scanned job')->value('id');

    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    expect(User::query()->where('email', 'like', 'e2e.%@sccit.test')->count())->toBe(3)
        ->and(QrCode::query()->where('code', SeedE2eFixtures::QR_CODE)->count())->toBe(1)
        ->and(PcUnit::query()->where('unit_code', 'E2E-PC-001')->count())->toBe(1)
        ->and(MaintenanceRecord::query()->where('title', 'E2E fixture — scanned job')->count())->toBe(1)
        // The same row, not a replacement — anything holding the uuid stays valid.
        ->and(MaintenanceRecord::query()->where('title', 'E2E fixture — scanned job')->value('id'))
        ->toBe($firstRecordId);
});

it('emits a JSON manifest carrying every key the browser suite reads', function (): void {
    $this->artisan('sccit:e2e-fixtures', ['--json' => true])->assertSuccessful();

    // Re-run capturing the output, because assertSuccessful() consumes it.
    $exit = $this->withoutMockingConsoleOutput()->artisan('sccit:e2e-fixtures', ['--json' => true]);
    $manifest = json_decode(app(Illuminate\Contracts\Console\Kernel::class)->output(), true);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($manifest)->toBeArray()
        ->and($manifest)->toHaveKeys(['password', 'users', 'qr', 'pc_unit', 'room', 'maintenance'])
        ->and($manifest['users'])->toHaveKeys(['administrator', 'technician', 'teacher'])
        ->and($manifest['qr']['code'])->toBe(SeedE2eFixtures::QR_CODE)
        ->and($manifest['qr']['scan_path'])->toBe('/qr/'.SeedE2eFixtures::QR_CODE);
});

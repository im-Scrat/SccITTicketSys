<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MaintenanceStatus;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Building;
use App\Models\Floor;
use App\Models\FloorPlanPosition;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deterministic fixtures for the browser (E2E) and accessibility suites — WP-2.7d.
 *
 * ── Why this is not a seeder call in DatabaseSeeder ───────────────────────
 * `DemoSeeder` builds its dataset from factories, so every email, room name and
 * QR code differs between runs. A browser test cannot sign in as "whoever the
 * factory invented this time", and the workaround — scraping a login from the
 * database mid-run — makes the test depend on the very data it is meant to
 * verify. This command creates a small, fixed, *named* set instead, and prints
 * exactly what it created so the harness never has to guess.
 *
 * It is invoked explicitly by scripts/e2e.sh and by nothing else. Adding it to
 * DatabaseSeeder would push verification accounts into every developer's
 * database as a side effect of an ordinary seed.
 *
 * ── Idempotency ───────────────────────────────────────────────────────────
 * Every record is matched on a natural key (`email`, `unit_code`, `code`) and
 * updated in place. Running the command twice produces the same fixture set,
 * not a second one — the harness re-seeds before every run and must not
 * accumulate rows across a week of test runs.
 *
 * ── The production guard ──────────────────────────────────────────────────
 * The production image bakes the whole `app/` tree, so this class exists inside
 * it. It refuses to run under APP_ENV=production because these are accounts
 * with a known, shared, documented password: creating them on a production
 * target would be creating a back door, not a test fixture. The guard is
 * asserted by tests/Feature/Verification/SeedE2eFixturesTest.php.
 */
class SeedE2eFixtures extends Command
{
    protected $signature = 'sccit:e2e-fixtures
                            {--json : print the fixture manifest as JSON and nothing else}';

    protected $description = 'Create the deterministic accounts and equipment used by the E2E and accessibility suites';

    /**
     * The one QR code the browser suite scans. Ten uppercase characters after
     * the `PC-` prefix, matching the shape QrService issues, so the fixture
     * exercises the same routing and validation a real label would.
     */
    public const QR_CODE = 'PC-E2EFIXTURE';

    public const ADMIN_EMAIL = 'e2e.admin@sccit.test';

    public const TECHNICIAN_EMAIL = 'e2e.tech@sccit.test';

    public const TEACHER_EMAIL = 'e2e.teacher@sccit.test';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('sccit:e2e-fixtures refuses to run under APP_ENV=production.');
            $this->line('These accounts share one documented password; on a production target that is a back door, not a fixture.');

            return self::FAILURE;
        }

        // From config, not env(): the production entrypoint runs config:cache
        // on every boot, and env() returns null outside config/ once it has.
        // See config/verification.php.
        $password = (string) config('verification.e2e_password');

        $manifest = DB::transaction(function () use ($password): array {
            $users = [
                'administrator' => $this->user(self::ADMIN_EMAIL, 'administrator', 'E2E', 'Administrator', 'E2E-ADM-001', $password),
                'technician' => $this->user(self::TECHNICIAN_EMAIL, 'technician', 'E2E', 'Technician', 'E2E-TEC-001', $password),
                'teacher' => $this->user(self::TEACHER_EMAIL, 'teacher', 'E2E', 'Teacher', 'E2E-TCH-001', $password),
            ];

            [$pcUnit, $room] = $this->equipment();
            $qr = $this->qrCode($pcUnit, $room);
            $maintenance = $this->maintenance($pcUnit, $users['technician']);
            $this->floorPlan($room, $pcUnit);

            return [
                'password' => $password,
                'users' => [
                    'administrator' => ['email' => $users['administrator']->email, 'uuid' => $users['administrator']->uuid],
                    'technician' => ['email' => $users['technician']->email, 'uuid' => $users['technician']->uuid],
                    'teacher' => ['email' => $users['teacher']->email, 'uuid' => $users['teacher']->uuid],
                ],
                'qr' => [
                    'code' => $qr->code,
                    'status' => $qr->status->value,
                    'scan_path' => '/qr/'.$qr->code,
                ],
                'pc_unit' => [
                    'uuid' => $pcUnit->uuid,
                    'unit_code' => $pcUnit->unit_code,
                    'pc_name' => $pcUnit->pc_name,
                ],
                'room' => ['uuid' => $room->uuid, 'name' => $room->name],
                'maintenance' => ['uuid' => $maintenance->uuid, 'status' => $maintenance->status->value],
            ];
        });

        if ($this->option('json')) {
            // Nothing but JSON on stdout — scripts/e2e.sh redirects this
            // straight into the file the Playwright fixtures read.
            $this->output->writeln((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('E2E fixtures ready.');
        foreach ($manifest['users'] as $role => $user) {
            $this->line(sprintf('  %-14s %s', $role, $user['email']));
        }
        $this->line('  password       '.$manifest['password']);
        $this->line('  QR code        '.$manifest['qr']['code'].'  ('.$manifest['qr']['scan_path'].')');
        $this->line('  PC unit        '.$manifest['pc_unit']['unit_code']);
        $this->line('  maintenance    '.$manifest['maintenance']['uuid'].' ('.$manifest['maintenance']['status'].')');

        return self::SUCCESS;
    }

    /**
     * One active account per role, matched on email so re-running updates rather
     * than duplicates. `email_verified_at` is set because an unverified account
     * cannot complete the sign-in the suite depends on.
     */
    private function user(string $email, string $roleSlug, string $first, string $last, string $employeeNumber, string $password): User
    {
        $role = Role::query()->where('slug', $roleSlug)->first()
            ?? throw new RuntimeException("Role '{$roleSlug}' is missing. Run `php artisan db:seed` first.");

        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'role_id' => $role->id,
                'employee_number' => $employeeNumber,
                'first_name' => $first,
                'last_name' => $last,
                'password' => $password, // hashed by the model's 'hashed' cast
                'email_verified_at' => now(),
                'status' => UserStatus::Active->value,
                'must_reset_password' => false,
            ],
        );
    }

    /**
     * A location hierarchy of its own rather than a reused demo room: the
     * accessibility suite screenshots these pages, and a fixture that borrowed
     * a random demo room would change what it renders whenever DemoSeeder ran.
     *
     * @return array{0: PcUnit, 1: Room}
     */
    private function equipment(): array
    {
        $building = Building::query()->updateOrCreate(
            ['code' => 'E2E'],
            ['name' => 'E2E Verification Building', 'is_active' => true],
        );

        // (building_id, floor_number) is uniquely indexed; 99 keeps the fixture
        // clear of the 1..10 range DemoSeeder's factory draws from.
        $floor = Floor::query()->updateOrCreate(
            ['building_id' => $building->id, 'floor_number' => 99],
            ['name' => 'E2E Floor'],
        );

        $room = Room::query()->updateOrCreate(
            ['code' => 'E2E-LAB-01'],
            [
                'floor_id' => $floor->id,
                'room_type' => RoomType::Laboratory->value,
                'name' => 'E2E Verification Lab',
                'room_number' => '999',
                'capacity' => 20,
                'is_active' => true,
            ],
        );

        $pcUnit = PcUnit::query()->updateOrCreate(
            ['unit_code' => 'E2E-PC-001'],
            [
                'room_id' => $room->id,
                'asset_tag' => 'E2E-AT-001',
                'hostname' => 'e2e-fixture-pc',
                'pc_name' => 'E2E Fixture PC',
                'brand' => 'Dell',
                'model' => 'E2E-Model-001',
                'serial_number' => 'E2E-SN-00000001',
                'status' => PcStatus::Available->value,
                'current_condition' => PcCondition::Working->value,
            ],
        );

        return [$pcUnit, $room];
    }

    /**
     * An active layout for the fixture room with one unit in **every** PC
     * status, so the floor-plan map (WP-C) is audited with all six shapes and
     * labels on screen rather than with an empty grid.
     *
     * The fixture PC is placed too. Its own status is whatever the maintenance
     * fixture left it in, which is why the six status-carrying units are
     * separate rows: a status that some other fixture may change cannot be the
     * one the map's coverage depends on.
     */
    private function floorPlan(Room $room, PcUnit $fixturePc): void
    {
        $layout = RoomLayout::query()->updateOrCreate(
            ['room_id' => $room->id, 'version' => 1],
            ['width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true],
        );

        foreach (PcStatus::cases() as $index => $status) {
            $number = $index + 1;

            $pc = PcUnit::query()->updateOrCreate(
                ['unit_code' => "E2E-FP-{$number}"],
                [
                    'room_id' => $room->id,
                    'asset_tag' => "E2E-FP-AT-{$number}",
                    'hostname' => "e2e-plan-pc-{$number}",
                    'pc_name' => "E2E Plan PC {$number}",
                    'status' => $status->value,
                    'current_condition' => PcCondition::Working->value,
                ],
            );

            FloorPlanPosition::query()->updateOrCreate(
                ['room_layout_id' => $layout->id, 'pc_unit_id' => $pc->id],
                ['pos_x' => 120 + 140 * $index, 'pos_y' => 200, 'rotation' => 0, 'z_index' => 0],
            );
        }

        FloorPlanPosition::query()->updateOrCreate(
            ['room_layout_id' => $layout->id, 'pc_unit_id' => $fixturePc->id],
            ['pos_x' => 120, 'pos_y' => 420, 'rotation' => 0, 'z_index' => 0],
        );
    }

    /**
     * The scannable label. Written directly rather than through QrService
     * because that service issues a *random* code by design (FR-QR-001) and the
     * browser suite needs a constant one; everything else about the row — the
     * single-target CHECK, the deep-link payload, the denormalized
     * `pc_units.qr_identifier` copy (FR-QR-002) — matches what the service
     * would have produced.
     */
    private function qrCode(PcUnit $pcUnit, Room $room): QrCode
    {
        $room->loadMissing('floor.building');
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $qr = QrCode::query()->updateOrCreate(
            ['code' => self::QR_CODE],
            [
                'pc_unit_id' => $pcUnit->id,
                'asset_id' => null,
                'payload' => $base.'/qr/'.self::QR_CODE,
                'location_label' => implode(' · ', array_filter([
                    $room->floor?->building?->name,
                    $room->floor?->name,
                    $room->name,
                ])),
                'status' => QrStatus::Active->value,
                'generated_at' => now(),
            ],
        );

        $pcUnit->forceFill(['qr_identifier' => self::QR_CODE])->save();

        return $qr;
    }

    /**
     * An in-progress corrective visit assigned to the E2E technician, so the
     * scanned panel has live work attached to it. Without one, the panel renders
     * its empty state and the suite would verify only that the route resolves.
     */
    private function maintenance(PcUnit $pcUnit, User $technician): MaintenanceRecord
    {
        $type = MaintenanceType::query()->orderBy('id')->first()
            ?? throw new RuntimeException('No maintenance types exist. Run `php artisan db:seed` first.');

        return MaintenanceRecord::query()->updateOrCreate(
            ['title' => 'E2E fixture — scanned job'],
            [
                'pc_unit_id' => $pcUnit->id,
                'technician_id' => $technician->id,
                'maintenance_type_id' => $type->id,
                'status' => MaintenanceStatus::InProgress->value,
                'diagnosis' => 'Fixture record created by sccit:e2e-fixtures for browser verification.',
                'started_at' => now(),
                'maintenance_date' => now(),
                'pc_status_before' => $pcUnit->status->value,
                'pc_condition_before' => $pcUnit->current_condition->value,
                'created_by' => $technician->id,
            ],
        );
    }
}

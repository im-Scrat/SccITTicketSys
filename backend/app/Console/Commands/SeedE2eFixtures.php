<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\KnowledgeBase\Notifications\PcPredictionGeneratedNotification;
use App\Enums\ComponentType;
use App\Enums\MaintenanceStatus;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Enums\PredictionRiskLevel;
use App\Enums\PredictionStatus;
use App\Enums\QrStatus;
use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\AiFailurePattern;
use App\Models\AiPrediction;
use App\Models\Building;
use App\Models\Floor;
use App\Models\FloorPlanPosition;
use App\Models\HardwareComponent;
use App\Models\HardwareReplacement;
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
            $predictions = $this->predictions($pcUnit, $users['technician'], $users['administrator']);

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
                'predictions' => $predictions,
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
        $this->line('  predictions    '.count($manifest['predictions']).' pending finding(s) on the fixture PC');

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

    /**
     * Two pending predictive-maintenance findings on the fixture PC, with the
     * repair history the review screen shows beside them (WP-M).
     *
     * Written directly rather than through `PcRiskAssessor`: that pipeline calls
     * a model provider, and a fixture that needed a live key would be no fixture.
     * What it must not do is invent a *shape*, so the `evidence` below is exactly
     * what the pipeline freezes at generation (observed facts, detected pattern,
     * the time window's basis) and the repairs it points at are real completed
     * `maintenance_records` — so the frozen evidence and the live history agree
     * about what happened to this machine.
     *
     * The two differ on purpose so the suite can assert both branches of the
     * display rule: the first states a time window, the second withholds one and
     * says why. Neither has a `probability`, because no calibrated model exists
     * and the pipeline never writes one.
     *
     * ── Re-runnable ────────────────────────────────────────────────────────
     * The specs *decide* findings, and a decided finding is final. So each run
     * first removes this PC's own predictions and patterns — the PC is the
     * suite's entity, so nothing else is touched — and files them pending again.
     *
     * ── Who is told ────────────────────────────────────────────────────────
     * Only the fixture administrator. Raising the pipeline's event would notify
     * *every* administrator in the database, and a developer's own account should
     * not collect a notification per test run.
     *
     * @return array<string, array{uuid: string, issue: string, window: int|null}>
     */
    private function predictions(PcUnit $pcUnit, User $technician, User $administrator): array
    {
        $type = MaintenanceType::query()->where('slug', 'corrective')->first()
            ?? throw new RuntimeException("The 'corrective' maintenance type is missing. Run `php artisan db:seed` first.");

        AiPrediction::query()->where('pc_unit_id', $pcUnit->id)->delete();
        AiFailurePattern::query()->where('pc_unit_id', $pcUnit->id)->delete();

        $component = HardwareComponent::query()->updateOrCreate(
            ['name' => 'E2E fixture power supply'],
            ['component_type' => ComponentType::PowerSupply->value],
        );

        $repairDays = [140, 80, 20];
        $intervals = [60, 60];

        foreach ($repairDays as $index => $daysAgo) {
            $completedAt = now()->subDays($daysAgo);

            $record = MaintenanceRecord::query()->updateOrCreate(
                ['title' => sprintf('E2E fixture — power supply repair %d', $index + 1)],
                [
                    'pc_unit_id' => $pcUnit->id,
                    'technician_id' => $technician->id,
                    'maintenance_type_id' => $type->id,
                    'status' => MaintenanceStatus::Completed->value,
                    'started_at' => $completedAt->copy()->subHour(),
                    'completed_at' => $completedAt,
                    'maintenance_date' => $completedAt,
                    'created_by' => $technician->id,
                ],
            );

            HardwareReplacement::query()->updateOrCreate(
                ['maintenance_record_id' => $record->id, 'old_component_id' => $component->id],
                ['pc_unit_id' => $pcUnit->id, 'new_component_id' => $component->id, 'quantity' => 1, 'replaced_at' => $completedAt],
            );
        }

        $first = now()->subDays($repairDays[0]);
        $last = now()->subDays(20);

        $pattern = AiFailurePattern::query()->create([
            'pc_unit_id' => $pcUnit->id,
            'hardware_component_id' => $component->id,
            'pattern_name' => 'Recurring Power Supply replacement',
            'detected_problem' => 'Power Supply replaced in 3 separate repairs',
            'occurrence_count' => 3,
            'average_days_between_failures' => 60,
            'confidence' => 0.72,
            'last_detected' => now(),
        ]);

        $withWindow = AiPrediction::query()->create([
            'pc_unit_id' => $pcUnit->id,
            'ai_failure_pattern_id' => $pattern->id,
            'predicted_issue' => 'E2E fixture: repeated power supply failure',
            'risk_level' => PredictionRiskLevel::High->value,
            'probability' => null,
            'confidence' => 0.72,
            'predicted_within_days' => 40,
            'explanation' => 'Three power supply replacements at regular 60-day intervals.',
            'recommendation' => 'Inspect ventilation and the power connections before the next interval elapses.',
            'evidence' => [
                'observed' => [
                    'completed_repairs' => 3,
                    'corrective_repairs' => 3,
                    'preventive_visits' => 0,
                    'first_completed_at' => $first->toIso8601String(),
                    'last_completed_at' => $last->toIso8601String(),
                    'components_replaced' => [['component_type' => 'power_supply', 'label' => 'Power Supply', 'count' => 3]],
                ],
                'patterns' => [[
                    'kind' => 'component',
                    'name' => 'Recurring Power Supply replacement',
                    'detected_problem' => 'Power Supply replaced in 3 separate repairs',
                    'occurrence_count' => 3,
                    'intervals_days' => $intervals,
                    'average_days_between' => 60,
                    'first_at' => $first->toIso8601String(),
                    'last_at' => $last->toIso8601String(),
                    'records' => [],
                ]],
                'time_window' => ['days' => 40, 'basis' => "The strongest pattern's average interval, less the days since its last occurrence."],
            ],
            'status' => PredictionStatus::Pending->value,
            'generated_at' => now(),
        ]);

        $withoutWindow = AiPrediction::query()->create([
            'pc_unit_id' => $pcUnit->id,
            'predicted_issue' => 'E2E fixture: repeated storage failure',
            'risk_level' => PredictionRiskLevel::Medium->value,
            'probability' => null,
            'confidence' => 0.55,
            'predicted_within_days' => null,
            'explanation' => 'Two storage replacements so far; too few to say when a third might follow.',
            'recommendation' => 'Check the drive\'s health indicators at the next visit.',
            'evidence' => [
                'observed' => [
                    'completed_repairs' => 3,
                    'corrective_repairs' => 3,
                    'preventive_visits' => 0,
                    'first_completed_at' => $first->toIso8601String(),
                    'last_completed_at' => $last->toIso8601String(),
                    'components_replaced' => [['component_type' => 'storage', 'label' => 'Storage', 'count' => 2]],
                ],
                'patterns' => [],
                'time_window' => ['days' => null, 'basis' => 'Fewer than three occurrences, so no time window is stated.'],
            ],
            'status' => PredictionStatus::Pending->value,
            'generated_at' => now(),
        ]);

        app(NotificationDispatcher::class)->sendTo($administrator, new PcPredictionGeneratedNotification($withWindow));

        return [
            'with_window' => ['uuid' => $withWindow->uuid, 'issue' => $withWindow->predicted_issue, 'window' => $withWindow->predicted_within_days],
            'without_window' => ['uuid' => $withoutWindow->uuid, 'issue' => $withoutWindow->predicted_issue, 'window' => $withoutWindow->predicted_within_days],
        ];
    }
}

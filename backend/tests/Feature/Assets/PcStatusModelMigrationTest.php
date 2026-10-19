<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Phase 2.11 — migration 2026_10_19_100000_replace_pc_status_model (SDD
 * DD-100). A fresh test database already has the new three-value CHECKs (the
 * baseline builds them from `PcStatus::values()`), so each mapping test first
 * runs the migration's own `down()` to restore the legacy domain, writes
 * legacy rows with raw inserts, then runs `up()` — exactly the path a
 * pre-Phase-2.11 production database takes.
 */

const PC_STATUS_MIGRATION = '2026_10_19_100000_replace_pc_status_model.php';

function pcStatusMigration(): object
{
    return require database_path('migrations/'.PC_STATUS_MIGRATION);
}

/** A PC row with a raw legacy status — never through the model or its cast. */
function legacyPc(string $status, array $overrides = []): int
{
    $pc = PcUnit::factory()->create($overrides);
    DB::table('pc_units')->where('id', $pc->id)->update(['status' => $status]);

    return $pc->id;
}

function rawStatus(int $id): string
{
    return (string) DB::table('pc_units')->where('id', $id)->value('status');
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);
    $this->reporter = userWithRole('teacher');

    pcStatusMigration()->down();
});

it('maps every legacy status to its new value', function (string $legacy, string $expected) {
    $id = legacyPc($legacy);

    pcStatusMigration()->up();

    expect(rawStatus($id))->toBe($expected);
})->with([
    ['available', 'available'],
    ['assigned', 'available'],
    ['online', 'available'],
    ['under_maintenance', 'pending_repair'],
    ['offline', 'needs_fix'],
]);

it('maps archived rows too, so a later restore lands on a valid value', function () {
    $id = legacyPc('online');
    DB::table('pc_units')->where('id', $id)->update(['deleted_at' => now()]);

    pcStatusMigration()->up();

    expect(rawStatus($id))->toBe('available');
});

it('archives a retired machine and audits both the status change and the archive', function () {
    $id = legacyPc('retired');

    pcStatusMigration()->up();

    $row = DB::table('pc_units')->where('id', $id)->first();
    $actions = ActivityLog::query()->where('subject_id', $id)->where('subject_type', PcUnit::class)->pluck('action')->all();

    expect($row->deleted_at)->not->toBeNull()
        ->and($row->status)->toBe('needs_fix')
        ->and($actions)->toEqualCanonicalizing(['pc_unit_status_changed', 'pc_unit_archived']);
});

it('keeps a retired machine with installed parts live as Needs Fix and records why', function () {
    $id = legacyPc('retired');
    PcComponentInstallation::factory()->create(['pc_unit_id' => $id]);

    pcStatusMigration()->up();

    $log = ActivityLog::query()->where('subject_id', $id)->where('action', 'pc_unit_status_changed')->sole();

    expect(DB::table('pc_units')->where('id', $id)->value('deleted_at'))->toBeNull()
        ->and(rawStatus($id))->toBe('needs_fix')
        ->and($log->properties['skipped_archive'])->toBe(['components' => 1, 'open_tickets' => 0]);
});

it('keeps a retired machine with an open ticket live', function () {
    $id = legacyPc('retired');
    ticketFor($this->reporter, 'open', ['pc_unit_id' => $id]);

    pcStatusMigration()->up();

    expect(DB::table('pc_units')->where('id', $id)->value('deleted_at'))->toBeNull();
});

it('marks an available machine with a ticket awaiting repair Pending Repair', function () {
    $id = legacyPc('available');
    ticketFor($this->reporter, 'in-progress', ['pc_unit_id' => $id]);

    pcStatusMigration()->up();

    $log = ActivityLog::query()->where('subject_id', $id)->where('action', 'pc_unit_status_changed')->sole();

    expect(rawStatus($id))->toBe('pending_repair')
        ->and($log->properties['changes']['status'])->toEqual(['from' => 'available', 'to' => 'pending_repair'])
        ->and($log->properties['reason'])->toBe('open_ticket_awaiting_repair');
});

it('does not treat a resolved ticket as awaiting repair', function () {
    $id = legacyPc('available');
    ticketFor($this->reporter, 'resolved', ['pc_unit_id' => $id]);

    pcStatusMigration()->up();

    expect(rawStatus($id))->toBe('available');
});

it('records the legacy value it replaced, so no status information is lost silently', function () {
    $id = legacyPc('offline');

    pcStatusMigration()->up();

    $log = ActivityLog::query()->where('subject_id', $id)->where('action', 'pc_unit_status_changed')->sole();

    expect($log->properties['changes']['status'])->toEqual(['from' => 'offline', 'to' => 'needs_fix'])
        ->and($log->properties['cause'])->toBe('status_model_migration');
});

it('maps the status a maintenance visit remembered', function () {
    $pc = PcUnit::factory()->create();
    $record = maintenanceFor(userWithRole('technician'), 'corrective', ['pc_unit_id' => $pc->id]);
    DB::table('maintenance_records')->where('id', $record->id)->update(['pc_status_before' => 'under_maintenance']);

    pcStatusMigration()->up();

    expect(DB::table('maintenance_records')->where('id', $record->id)->value('pc_status_before'))->toBe('pending_repair');
});

it('refuses a legacy status once migrated (pc_units_status_check)', function () {
    pcStatusMigration()->up();
    $pc = PcUnit::factory()->create();

    expect(fn () => DB::table('pc_units')->where('id', $pc->id)->update(['status' => 'online']))
        ->toThrow(QueryException::class, 'pc_units_status_check');
});

it('refuses a legacy remembered status once migrated (maintenance_records_pc_status_before_check)', function () {
    pcStatusMigration()->up();
    $record = maintenanceFor(userWithRole('technician'), 'corrective', ['pc_unit_id' => PcUnit::factory()->create()->id]);

    expect(fn () => DB::table('maintenance_records')->where('id', $record->id)->update(['pc_status_before' => 'retired']))
        ->toThrow(QueryException::class, 'maintenance_records_pc_status_before_check');
});

it('maps back onto the legacy domain on rollback', function () {
    pcStatusMigration()->up();
    $repair = PcUnit::factory()->create(['status' => 'pending_repair']);
    $broken = PcUnit::factory()->create(['status' => 'needs_fix']);

    pcStatusMigration()->down();

    expect(rawStatus($repair->id))->toBe('under_maintenance')
        ->and(rawStatus($broken->id))->toBe('offline');
});

it('reads a legacy value through the model without throwing, for the deploy window before migrate', function () {
    $id = legacyPc('under_maintenance');

    expect(PcUnit::query()->findOrFail($id)->status->value)->toBe('pending_repair');
});

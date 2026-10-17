<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Models\ActivityLog;
use App\Models\TicketStatusHistory;
use App\Models\TicketUpdate;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\DB;

/*
 * `tickets:end-stale-assignments` — the one-time repair for tickets that reached a
 * terminal status before TicketLifecycle ended their assignment (SDD DD-83).
 *
 * The stale rows are fabricated the way they were really produced: the ticket row
 * and its history are written directly, bypassing TicketLifecycle, which is exactly
 * what the pre-fix code did to the assignment.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->technician = userWithRole('technician');
    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');

    /**
     * A terminal ticket whose assignment was left active, with a history that
     * ends `resolved (by $resolvedBy) → closed`, or just `open → $terminal`.
     */
    $this->stale = function (string $terminal, ?string $resolvedBy = null): array {
        $ticket = ticketFor($this->teacher, $terminal, ['assigned_technician_id' => $this->technician->id]);
        $assignment = assign($ticket, $this->technician, AssignmentStatus::Pending);
        $resolvedAt = now()->subDays(2)->startOfSecond();

        if ($resolvedBy !== null) {
            TicketStatusHistory::query()->create([
                'ticket_id' => $ticket->id,
                'from_status_id' => ticketStatus('in-progress')->id,
                'to_status_id' => ticketStatus('resolved')->id,
                'changed_by' => ($resolvedBy === 'technician' ? $this->technician : $this->admin)->id,
                'created_at' => $resolvedAt,
            ]);
        }

        TicketStatusHistory::query()->create([
            'ticket_id' => $ticket->id,
            'from_status_id' => ticketStatus($resolvedBy !== null ? 'resolved' : 'open')->id,
            'to_status_id' => ticketStatus($terminal)->id,
            'changed_by' => $this->teacher->id,
            'created_at' => now()->subDay(),
        ]);

        return [$ticket, $assignment, $resolvedAt];
    };
});

it('reports and writes nothing under --dry-run', function () {
    [, $viaTechnician] = ($this->stale)('closed', 'technician');
    [, $cancelled] = ($this->stale)('cancelled');

    $this->artisan('tickets:end-stale-assignments', ['--dry-run' => true])
        ->expectsOutputToContain('2 assignment(s) would be ended: 1 completed, 1 cancelled.')
        ->assertSuccessful();

    expect($viaTechnician->fresh()->status)->toBe(AssignmentStatus::Pending)
        ->and($cancelled->fresh()->status)->toBe(AssignmentStatus::Pending)
        ->and(TicketUpdate::query()->where('update_type', 'assignment')->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', ActivityAction::TicketAssignmentEnded->value)->exists())->toBeFalse();
});

it('applies the lifecycle mapping to existing stale assignments', function () {
    [, $viaTechnician, $resolvedAt] = ($this->stale)('closed', 'technician');
    [, $viaAdmin] = ($this->stale)('closed', 'admin');
    [, $skipped] = ($this->stale)('closed');
    [, $cancelled] = ($this->stale)('cancelled');

    $updatedBefore = DB::table('technician_assignments')->where('id', $viaTechnician->id)->value('updated_at');

    $this->artisan('tickets:end-stale-assignments')
        ->expectsOutputToContain('4 assignment(s) were ended: 1 completed, 3 cancelled.')
        ->assertSuccessful();

    expect($viaTechnician->fresh()->status)->toBe(AssignmentStatus::Completed)
        ->and($viaTechnician->fresh()->completed_at->equalTo($resolvedAt))->toBeTrue()
        ->and($viaAdmin->fresh()->status)->toBe(AssignmentStatus::Cancelled)
        ->and($skipped->fresh()->status)->toBe(AssignmentStatus::Cancelled)
        ->and($cancelled->fresh()->status)->toBe(AssignmentStatus::Cancelled)
        // The repair, like the fix, is not the technician's activity.
        ->and(DB::table('technician_assignments')->where('id', $viaTechnician->id)->value('updated_at'))->toBe($updatedBefore)
        ->and(ActivityLog::query()->where('action', ActivityAction::TicketAssignmentEnded->value)->count())->toBe(4)
        ->and(TicketUpdate::query()->where('update_type', 'assignment')->whereNull('user_id')->count())->toBe(4);
});

it('is a no-op on a second run', function () {
    ($this->stale)('closed', 'technician');

    $this->artisan('tickets:end-stale-assignments')->assertSuccessful();
    $logs = ActivityLog::query()->count();
    $updates = TicketUpdate::query()->count();

    $this->artisan('tickets:end-stale-assignments')
        ->expectsOutputToContain('No active assignments are attached to closed or cancelled tickets.')
        ->assertSuccessful();

    expect(ActivityLog::query()->count())->toBe($logs)
        ->and(TicketUpdate::query()->count())->toBe($updates);
});

it('leaves non-terminal tickets and already-ended assignments untouched', function () {
    // A resolved ticket legitimately keeps its assignment until it closes.
    $resolved = ticketFor($this->teacher, 'resolved', ['assigned_technician_id' => $this->technician->id]);
    $live = assign($resolved, $this->technician, AssignmentStatus::InProgress);

    $declined = ticketFor($this->teacher, 'closed');
    $ended = assign($declined, $this->technician, AssignmentStatus::Declined);

    $this->artisan('tickets:end-stale-assignments')->assertSuccessful();

    expect($live->fresh()->status)->toBe(AssignmentStatus::InProgress)
        ->and($ended->fresh()->status)->toBe(AssignmentStatus::Declined)
        ->and(ActivityLog::query()->where('action', ActivityAction::TicketAssignmentEnded->value)->exists())->toBeFalse();
});

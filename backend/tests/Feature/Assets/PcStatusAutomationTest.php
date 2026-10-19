<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Events\PcStatusChanged;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Enums\PcStatus;
use App\Models\ActivityLog;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Phase 2.11 — tickets and repairs drive a PC's repair status
 * (`PcStatusAutomation`, SDD DD-100; owner decision 2026-10-19). One test per
 * row of the rules table, plus the edges: Needs Fix is never written or
 * cleared by automation, "resolved" counts as repaired, and nothing releases a
 * machine while something else is still pending.
 */

function automationTicketPayload(PcUnit $pc): array
{
    return [
        'title' => 'Computer will not boot',
        'description' => 'The computer shows a black screen after the logo.',
        'category' => 'hardware',
        'pc_unit' => $pc->uuid,
    ];
}

function moveTicket(Ticket $ticket, string $slug): void
{
    app(TicketLifecycle::class)->transition(
        $ticket->fresh(),
        TicketStatus::query()->where('slug', $slug)->sole(),
        test()->admin,
    );
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->room = Room::factory()->create();
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'status' => PcStatus::Available->value]);
});

/* ------------------------------------------------------------ tickets */

it('marks an Available machine Pending Repair when a fault is reported against it', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', automationTicketPayload($this->pc))->assertCreated();

    expect($this->pc->refresh()->status)->toBe(PcStatus::PendingRepair);
});

it('audits the automatic change in the shape the asset history already renders', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', automationTicketPayload($this->pc))->assertCreated();

    $log = ActivityLog::query()
        ->where('action', ActivityAction::PcUnitStatusChanged->value)
        ->where('subject_id', $this->pc->id)
        ->sole();

    expect($log->properties['changes']['status'])->toEqual(['from' => 'available', 'to' => 'pending_repair'])
        ->and($log->properties['cause'])->toBe('ticket_opened')
        ->and($log->properties['automated'])->toBeTrue()
        ->and($log->user_id)->toBe($this->teacher->id);
});

it('announces the change on the machine\'s Floorplan channel', function () {
    Event::fake([PcStatusChanged::class]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', automationTicketPayload($this->pc))->assertCreated();

    Event::assertDispatched(PcStatusChanged::class, fn (PcStatusChanged $e) => $e->pcUuid === $this->pc->uuid
        && $e->roomUuid === $this->room->uuid
        && $e->status['value'] === 'pending_repair'
        && $e->status['tone'] === 'warning');
});

it('never downgrades a machine a person marked Needs Fix', function () {
    $this->pc->forceFill(['status' => PcStatus::NeedsFix->value])->save();

    $this->actingAs($this->teacher)->postJson('/api/tickets', automationTicketPayload($this->pc))->assertCreated();

    expect($this->pc->refresh()->status)->toBe(PcStatus::NeedsFix);
});

it('returns the machine to Available when its ticket is resolved', function () {
    $ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pc->id]);
    $this->pc->forceFill(['status' => PcStatus::PendingRepair->value])->save();

    moveTicket($ticket, 'resolved');

    expect($this->pc->refresh()->status)->toBe(PcStatus::Available);
});

it('returns the machine to Available when its ticket is cancelled', function () {
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pc->id]);
    $this->pc->forceFill(['status' => PcStatus::PendingRepair->value])->save();

    moveTicket($ticket, 'cancelled');

    expect($this->pc->refresh()->status)->toBe(PcStatus::Available);
});

it('marks the machine Pending Repair again when a resolved ticket is reopened', function () {
    $ticket = ticketFor($this->teacher, 'resolved', ['pc_unit_id' => $this->pc->id]);

    moveTicket($ticket, 'in-progress');

    expect($this->pc->refresh()->status)->toBe(PcStatus::PendingRepair);
});

it('does nothing when a ticket moves between two awaiting states', function () {
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pc->id]);

    moveTicket($ticket, 'in-progress');

    // Still Available: the move did not *start* awaiting repair (it already
    // was), so automation has nothing to say — a person set this status.
    expect($this->pc->refresh()->status)->toBe(PcStatus::Available);
});

it('keeps the machine Pending Repair while another of its tickets still awaits repair', function () {
    $first = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pc->id]);
    ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pc->id]);
    $this->pc->forceFill(['status' => PcStatus::PendingRepair->value])->save();

    moveTicket($first, 'resolved');

    expect($this->pc->refresh()->status)->toBe(PcStatus::PendingRepair);
});

it('keeps the machine Pending Repair while a repair visit is still active', function () {
    $ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pc->id]);
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);
    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();

    moveTicket($ticket, 'resolved');

    expect($this->pc->refresh()->status)->toBe(PcStatus::PendingRepair);
});

it('never clears Needs Fix when the last ticket closes', function () {
    $ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pc->id]);
    $this->pc->forceFill(['status' => PcStatus::NeedsFix->value])->save();

    moveTicket($ticket, 'resolved');

    expect($this->pc->refresh()->status)->toBe(PcStatus::NeedsFix);
});

it('touches no machine for a ticket without one', function () {
    $ticket = ticketFor($this->teacher, 'in-progress');

    moveTicket($ticket, 'resolved');

    expect(ActivityLog::query()->where('action', ActivityAction::PcUnitStatusChanged->value)->count())->toBe(0);
});

it('leaves an archived machine alone', function () {
    $ticket = ticketFor($this->teacher, 'resolved', ['pc_unit_id' => $this->pc->id]);
    $this->pc->delete();

    moveTicket($ticket, 'in-progress');

    expect(PcUnit::withTrashed()->findOrFail($this->pc->id)->status)->toBe(PcStatus::Available);
});

/* ------------------------------------------------------------ repairs */

it('keeps the machine Pending Repair after its repair completes while its ticket still awaits repair', function () {
    ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pc->id]);
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Reseated RAM'])->assertOk();

    // The ticket closing is what releases it (next test).
    expect($this->pc->refresh()->status)->toBe(PcStatus::PendingRepair);
});

it('releases the machine once both the repair and the ticket are finished', function () {
    $ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pc->id]);
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    moveMaintenance($this->technician, $record, 'completed', ['resolution' => 'Reseated RAM'])->assertOk();
    moveTicket($ticket, 'resolved');

    expect($this->pc->refresh()->status)->toBe(PcStatus::Available);
});

it('turns a displaced Available into Pending Repair when a repair is cancelled but a ticket still awaits', function () {
    ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pc->id]);
    $record = maintenanceFor($this->technician, 'preventive', ['pc_unit_id' => $this->pc->id]);

    moveMaintenance($this->technician, $record, 'in_progress')->assertOk();
    moveMaintenance($this->technician, $record, 'cancelled', ['reason' => 'Wrong machine'])->assertOk();

    expect($this->pc->refresh()->status)->toBe(PcStatus::PendingRepair);
});

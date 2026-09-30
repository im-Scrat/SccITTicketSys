<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\PermissionGrantType;
use App\Models\HardwareComponent;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WP-K — a technician completing assigned repair work into a REAL maintenance
 * record (SRS UC-03 "(optional) create maintenance record → resolve"; the full
 * technician flow's steps 5–10), and the authorization that keeps that record
 * honest about which ticket it belongs to.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->withHeader('Accept', 'application/json');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->room = Room::factory()->create();
    $this->pcUnit = PcUnit::factory()->create([
        'room_id' => $this->room->id,
        'status' => PcStatus::Online->value,
    ]);

    $this->ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pcUnit->id]);
    assign($this->ticket, $this->technician, AssignmentStatus::InProgress);
});

/* ------------------------------------------- the repair section on the ticket */

it('offers to start a repair record on an assigned ticket that names a PC', function (): void {
    $this->actingAs($this->technician)
        ->getJson("/api/tickets/assigned/{$this->ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.repair.records', [])
        ->assertJsonPath('meta.repair.can_start', true);
});

it('does not offer one when the ticket names no machine to hold the history', function (): void {
    $noPc = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => null]);
    assign($noPc, $this->technician, AssignmentStatus::InProgress);

    $this->actingAs($this->technician)
        ->getJson("/api/tickets/assigned/{$noPc->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.repair.can_start', false);
});

/* ------------------------------------------------ the full capture, end to end */

it('captures the whole repair into one real maintenance record linked to the ticket', function (): void {
    // 1. Start the record from the assigned ticket.
    $created = $this->actingAs($this->technician)->postJson('/api/maintenance', [
        'title' => "Repair: {$this->ticket->ticket_number}",
        'type' => 'corrective',
        'pc_unit' => $this->pcUnit->uuid,
        'ticket' => $this->ticket->uuid,
        'diagnosis' => 'PSU fan seized; unit shuts down under load.',
    ])->assertCreated();
    $uuid = $created->json('data.id');

    // It now shows on the ticket, and a second one is not offered while it is open.
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$this->ticket->uuid}")
        ->assertJsonPath('meta.repair.records.0.id', $uuid)
        ->assertJsonPath('meta.repair.can_start', false);

    // 2. Work it through the Maintenance module's own endpoints.
    $this->actingAs($this->technician)->putJson("/api/maintenance/{$uuid}/status", ['status' => 'in_progress'])->assertOk();
    $this->actingAs($this->technician)->putJson("/api/maintenance/{$uuid}", [
        'root_cause' => 'Dust ingress over two terms without cleaning.',
        'preventive_recommendation' => 'Quarterly dust cleaning for this lab.',
    ])->assertOk();
    $this->actingAs($this->technician)->postJson("/api/maintenance/{$uuid}/notes", ['body' => 'Spare PSU taken from store room B.'])->assertCreated();
    $this->actingAs($this->technician)->postJson("/api/maintenance/{$uuid}/replacements", [
        'new_component' => HardwareComponent::factory()->create()->id,
        'quantity' => 1,
        'reason' => 'Failed PSU',
    ])->assertCreated();
    $this->actingAs($this->technician)->post("/api/maintenance/{$uuid}/evidence", [
        'image_type' => 'after',
        'file' => UploadedFile::fake()->image('after.jpg'),
    ])->assertCreated();

    // 3. Complete it.
    $this->actingAs($this->technician)->putJson("/api/maintenance/{$uuid}/status", [
        'status' => 'completed',
        'resolution' => 'Replaced the PSU; soak-tested for 30 minutes.',
    ])->assertOk();

    $record = MaintenanceRecord::query()->where('uuid', $uuid)->firstOrFail();

    expect($record->status)->toBe(MaintenanceStatus::Completed)
        ->and($record->ticket_id)->toBe($this->ticket->id)
        ->and($record->pc_unit_id)->toBe($this->pcUnit->id)
        ->and($record->technician_id)->toBe($this->technician->id)
        ->and($record->diagnosis)->toContain('PSU fan seized')
        ->and($record->root_cause)->toContain('Dust ingress')
        ->and($record->resolution)->toContain('Replaced the PSU')
        ->and($record->preventive_recommendation)->toContain('Quarterly')
        ->and($record->notes()->count())->toBe(1)
        ->and($record->hardwareReplacements()->count())->toBe(1)
        ->and($record->images()->count())->toBe(1);

    // PC and location context: the record resolves to this machine in its room.
    $this->actingAs($this->technician)->getJson("/api/maintenance/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.target.id', $this->pcUnit->uuid)
        ->assertJsonPath('data.location.room', $this->room->name)
        ->assertJsonPath('data.ticket.id', $this->ticket->uuid);

    // Completed: it stays listed, and a new one may be started for a return visit.
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$this->ticket->uuid}")
        ->assertJsonPath('meta.repair.records.0.status.value', 'completed')
        ->assertJsonPath('meta.repair.records.0.status.tone', 'success')
        ->assertJsonPath('meta.repair.records.0.status.is_open', false)
        ->assertJsonPath('meta.repair.can_start', true);
});

/* ---------------------------------------------- linking to a ticket is scoped */

it('refuses to link a record to a ticket the technician is not assigned to', function (): void {
    $notMine = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pcUnit->id]);
    assign($notMine, $this->otherTechnician, AssignmentStatus::InProgress);

    $this->actingAs($this->technician)->postJson('/api/maintenance', [
        'title' => 'Sneaky link',
        'type' => 'corrective',
        'pc_unit' => $this->pcUnit->uuid,
        'ticket' => $notMine->uuid,
    ])->assertUnprocessable()->assertJsonValidationErrors('ticket');

    expect(MaintenanceRecord::query()->count())->toBe(0);
});

it('answers a missing ticket exactly like an inaccessible one — no existence oracle', function (): void {
    $notMine = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pcUnit->id]);

    $message = fn (string $ticketUuid) => $this->actingAs($this->technician)->postJson('/api/maintenance', [
        'title' => 'Probe',
        'type' => 'corrective',
        'pc_unit' => $this->pcUnit->uuid,
        'ticket' => $ticketUuid,
    ])->assertUnprocessable()->json('errors.ticket.0');

    expect($message($notMine->uuid))->toBe($message('00000000-0000-4000-8000-000000000000'));
});

it('lets an administrator link any ticket', function (): void {
    $this->actingAs($this->admin)->postJson('/api/maintenance', [
        'title' => 'Admin-opened repair',
        'type' => 'corrective',
        'pc_unit' => $this->pcUnit->uuid,
        'ticket' => $this->ticket->uuid,
        'technician' => $this->technician->uuid,
    ])->assertCreated();
});

it('refuses to re-point an existing record at a ticket the technician cannot open', function (): void {
    $record = maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pcUnit->id, 'ticket_id' => $this->ticket->id]);
    $notMine = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pcUnit->id]);

    $this->actingAs($this->technician)
        ->putJson("/api/maintenance/{$record->uuid}", ['ticket' => $notMine->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('ticket');

    expect($record->fresh()->ticket_id)->toBe($this->ticket->id);
});

it('accepts an unchanged link on an edit, even one an administrator made', function (): void {
    // An administrator linked a ticket this technician is not assigned to.
    $adminsTicket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pcUnit->id]);
    $record = maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pcUnit->id, 'ticket_id' => $adminsTicket->id]);

    // Re-sending it unchanged alongside a real edit is not a new link.
    $this->actingAs($this->technician)->putJson("/api/maintenance/{$record->uuid}", [
        'ticket' => $adminsTicket->uuid,
        'diagnosis' => 'Updated after a second look.',
    ])->assertOk();
});

/* --------------------------------------------------------- no floor plan */

it('grants a technician no floor-plan access, repair record or not — even holding floorplan.view', function (): void {
    // The seeder does not grant technicians `floorplan.view` (asserted in
    // WP-B's FloorPlanAuthorizationTest), but a drifted database can: the
    // development database's technician role holds it (found during WP-K). So
    // prove the worst case — the permission present — still opens nothing,
    // because WP-B's policy also requires the administrator role.
    DB::table('user_permissions')->insert([
        'user_id' => $this->technician->id,
        'permission_id' => Permission::query()->where('name', 'floorplan.view')->value('id'),
        'grant_type' => PermissionGrantType::Grant->value,
    ]);
    app(PermissionResolver::class)->forget($this->technician);
    expect($this->technician->hasPermissionTo('floorplan.view'))->toBeTrue();

    maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pcUnit->id, 'ticket_id' => $this->ticket->id]);

    // Read and write, both refused.
    $this->actingAs($this->technician)->getJson("/api/admin/floor-plan/rooms/{$this->room->uuid}")->assertForbidden();
    $this->actingAs($this->technician)
        ->postJson("/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts", ['width' => 800, 'height' => 600])
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Events\PcStatusChanged;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use Database\Seeders\MaintenanceTypeSeeder;
use Illuminate\Support\Facades\Event;

/**
 * WP-E — the two floor-plan broadcast events: when they are (and are not)
 * dispatched, which channel they carry, and what actually goes out on the
 * wire. Every payload assertion is against the **encoded** `broadcastWith()`
 * output, per CLAUDE.md §6 — never against the event object's properties,
 * which a later change could widen without this test noticing.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->withHeader('Origin', (string) config('app.url'));

    $this->admin = userWithRole('administrator');
    $this->room = Room::factory()->create();
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
});

// ── PcStatusChanged — direct edit (Assets) ──────────────────────────────────

it('dispatches PcStatusChanged when an Administrator changes a PC unit\'s status directly', function (): void {
    Event::fake([PcStatusChanged::class]);
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-DIRECT', 'status' => PcStatus::Available->value]);
    $roomUuid = $this->room->uuid;

    $this->actingAs($this->admin)
        ->putJson("/api/admin/pc-units/{$pc->uuid}", ['status' => PcStatus::NeedsFix->value])
        ->assertOk();

    Event::assertDispatched(PcStatusChanged::class, function (PcStatusChanged $event) use ($pc, $roomUuid): bool {
        expect($event->broadcastOn()[0]->name)->toBe("private-floor-plan.room.{$roomUuid}");
        expect($event->broadcastAs())->toBe('floor-plan.pc-status-changed');

        $wire = json_decode((string) json_encode($event->broadcastWith()), true);
        expect($wire)->toBe([
            'pc' => [
                'id' => $pc->uuid,
                'name' => 'PC-DIRECT',
                'unit_code' => $pc->unit_code,
                'status' => ['value' => 'needs_fix', 'label' => PcStatus::NeedsFix->label(), 'tone' => PcStatus::NeedsFix->tone()],
            ],
        ]);

        $encoded = (string) json_encode($event->broadcastWith());
        foreach (['serial_number', 'ip_address', 'mac_address', 'room_id'] as $field) {
            expect($encoded)->not->toContain($field);
        }

        return true;
    });
});

it('does not dispatch PcStatusChanged when an unrelated field changes', function (): void {
    Event::fake([PcStatusChanged::class]);
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/pc-units/{$pc->uuid}", ['notes' => 'Recabled the network drop.'])
        ->assertOk();

    Event::assertNotDispatched(PcStatusChanged::class);
});

it('does not dispatch PcStatusChanged for a PC unit with no room', function (): void {
    Event::fake([PcStatusChanged::class]);
    $pc = PcUnit::factory()->create(['room_id' => null, 'status' => PcStatus::Available->value]);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/pc-units/{$pc->uuid}", ['status' => PcStatus::NeedsFix->value])
        ->assertOk();

    Event::assertNotDispatched(PcStatusChanged::class);
});

// ── PcStatusChanged — the maintenance-driven effect ─────────────────────────

it('dispatches PcStatusChanged when starting maintenance work moves the PC to under_maintenance', function (): void {
    Event::fake([PcStatusChanged::class]);
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-MAINT', 'status' => PcStatus::Available->value]);
    $record = maintenanceFor($this->admin, overrides: ['pc_unit_id' => $pc->id, 'status' => MaintenanceStatus::Scheduled->value]);

    $this->actingAs($this->admin)
        ->putJson("/api/maintenance/{$record->uuid}/status", ['status' => MaintenanceStatus::InProgress->value])
        ->assertOk();

    Event::assertDispatched(PcStatusChanged::class, function (PcStatusChanged $event) use ($pc): bool {
        $wire = json_decode((string) json_encode($event->broadcastWith()), true);

        return $wire['pc']['id'] === $pc->uuid
            && $wire['pc']['status']['value'] === PcStatus::PendingRepair->value;
    });
});

it('does not dispatch PcStatusChanged for a maintenance transition with no PC effect', function (): void {
    Event::fake([PcStatusChanged::class]);
    $record = maintenanceFor($this->admin, overrides: ['status' => MaintenanceStatus::Scheduled->value]);

    $this->actingAs($this->admin)
        ->putJson("/api/maintenance/{$record->uuid}/status", ['status' => MaintenanceStatus::Cancelled->value])
        ->assertOk();

    Event::assertNotDispatched(PcStatusChanged::class);
});

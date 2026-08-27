<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Enums\PcStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Consumable;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;

beforeEach(fn () => seedRbac());

it('refuses to archive a room that still holds a live PC unit', function () {
    $admin = userWithRole('administrator');
    $room = Room::factory()->create();
    PcUnit::factory()->create(['room_id' => $room->id, 'status' => PcStatus::Online->value]);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$room->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'location_in_use')
        ->assertJsonPath('level', 'room')
        ->assertJsonPath('blockers.pc_units', 1)
        ->assertJsonPath('rooms.0.id', $room->uuid);

    expect(Room::query()->whereKey($room->id)->exists())->toBeTrue();
});

it('refuses to archive a room holding live assets or stock', function () {
    $admin = userWithRole('administrator');

    $assetRoom = Room::factory()->create();
    Asset::factory()->create(['current_room_id' => $assetRoom->id, 'status' => AssetStatus::Deployed->value]);

    $stockRoom = Room::factory()->create();
    Consumable::factory()->create(['current_room_id' => $stockRoom->id, 'quantity_on_hand' => 12]);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$assetRoom->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('blockers.assets', 1);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$stockRoom->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('blockers.consumables', 1);
});

it('refuses to archive a room with an open ticket but allows it once closed', function () {
    $admin = userWithRole('administrator');
    $reporter = userWithRole('teacher');
    $room = Room::factory()->create();

    $open = TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false]);
    $closed = TicketStatus::factory()->create(['is_open' => false, 'is_terminal' => true]);

    $ticket = Ticket::factory()->create([
        'reporter_id' => $reporter->id,
        'room_id' => $room->id,
        'category_id' => TicketCategory::factory(),
        'priority_id' => TicketPriority::factory(),
        'current_status_id' => $open->id,
    ]);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$room->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('blockers.open_tickets', 1);

    // History never blocks: once the ticket reaches a closed status the room retires.
    $ticket->update(['current_status_id' => $closed->id]);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$room->uuid}")->assertOk();
});

it('ignores retired and disposed equipment when deciding whether a room is in use', function () {
    $admin = userWithRole('administrator');
    $room = Room::factory()->create();

    PcUnit::factory()->create(['room_id' => $room->id, 'status' => PcStatus::Retired->value]);
    Asset::factory()->create(['current_room_id' => $room->id, 'status' => AssetStatus::Disposed->value]);
    Consumable::factory()->create(['current_room_id' => $room->id, 'quantity_on_hand' => 0]);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$room->uuid}")->assertOk();
});

it('moves occupants to another room so the source can then be archived', function () {
    $admin = userWithRole('administrator');
    $from = Room::factory()->create();
    $to = Room::factory()->create();

    $pc = PcUnit::factory()->create(['room_id' => $from->id, 'status' => PcStatus::Online->value]);
    $asset = Asset::factory()->create(['current_room_id' => $from->id, 'status' => AssetStatus::Deployed->value]);
    $consumable = Consumable::factory()->create(['current_room_id' => $from->id, 'quantity_on_hand' => 5]);

    $this->actingAs($admin)->postJson("/api/admin/rooms/{$from->uuid}/reassign", [
        'to_room' => $to->uuid,
    ])->assertOk()
        ->assertJsonPath('moved.pc_units', 1)
        ->assertJsonPath('moved.assets', 1)
        ->assertJsonPath('moved.consumables', 1);

    expect($pc->fresh()->room_id)->toBe($to->id)
        ->and($asset->fresh()->current_room_id)->toBe($to->id)
        ->and($consumable->fresh()->current_room_id)->toBe($to->id);

    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$from->uuid}")->assertOk();

    // Both rooms carry the reassignment in their timelines.
    $this->actingAs($admin)->getJson("/api/admin/rooms/{$to->uuid}/audit")
        ->assertOk()
        ->assertJsonPath('data.0.action', 'location_occupants_reassigned');
});

it('rejects a reassignment to the same room or to an unknown room', function () {
    $admin = userWithRole('administrator');
    $room = Room::factory()->create();

    $this->actingAs($admin)->postJson("/api/admin/rooms/{$room->uuid}/reassign", [
        'to_room' => $room->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors(['to_room']);

    $this->actingAs($admin)->postJson("/api/admin/rooms/{$room->uuid}/reassign", [
        'to_room' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonValidationErrors(['to_room']);
});

it('blocks a building archive when any room in it is in use and names the room', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $empty = Room::factory()->create(['floor_id' => $floor->id]);
    $occupied = Room::factory()->create(['floor_id' => $floor->id]);
    PcUnit::factory()->create(['room_id' => $occupied->id, 'status' => PcStatus::Online->value]);

    $response = $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('level', 'building')
        ->assertJsonPath('blockers.pc_units', 1);

    // Only the blocking room is listed, not every room in the building.
    expect($response->json('rooms'))->toHaveCount(1)
        ->and($response->json('rooms.0.id'))->toBe($occupied->uuid)
        ->and($empty->fresh()->deleted_at)->toBeNull();
});

it('reports the blocker state on the room detail meta so the UI can warn ahead of time', function () {
    $admin = userWithRole('administrator');
    $room = Room::factory()->create();
    PcUnit::factory()->create(['room_id' => $room->id, 'status' => PcStatus::Online->value]);

    $this->actingAs($admin)->getJson("/api/admin/rooms/{$room->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.in_use', true)
        ->assertJsonPath('meta.blockers.pc_units', 1);
});

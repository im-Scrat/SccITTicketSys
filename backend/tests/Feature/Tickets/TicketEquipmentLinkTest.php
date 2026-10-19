<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ComponentType;
use App\Enums\PcStatus;
use App\Models\Building;
use App\Models\Floor;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Phase 2.9 — a ticket may name a piece of equipment that is not a PC
 * (SDD DD-78/79): `tickets.asset_id`, at most one item per ticket.
 *
 * The server accepts exactly what the report form's room-scoped lookup offers
 * (RoomEquipmentLookupTest): a selectable room, a reportable PC or in-service
 * asset, the item in that room — or, with no room named, an item whose own
 * room is selectable. Everything downstream that knew only about PCs — the
 * projections, the asset archive guard, the asset timeline, the repair record —
 * learns about the asset link here.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function equipmentPayload(array $extra = []): array
{
    return [
        'title' => 'Printer jams on every page',
        'description' => 'The printer jams on every page and shows a paper error.',
        'category' => 'hardware',
        ...$extra,
    ];
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);
    // Creation dispatches the AI pre-screening; nothing here is about it.
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');

    $this->room = Room::factory()->create(['name' => 'Lab A']);
    $this->otherRoom = Room::factory()->create(['name' => 'Office B']);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id]);
    $this->printer = assetOfType(ComponentType::Printer, [
        'current_room_id' => $this->room->id,
        'asset_tag' => 'PRN-0001',
        'serial_number' => 'SERIALPRINTERSECRET',
        'purchase_price' => 1234.56,
        'status' => AssetStatus::Deployed->value,
    ]);
});

/* ---------------------------------------------------------------- creation */

it('files a ticket against an asset in its room', function () {
    $response = $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'room' => $this->room->uuid,
        'asset' => $this->printer->uuid,
    ]))->assertCreated();

    $response->assertJsonPath('data.asset.id', $this->printer->uuid)
        ->assertJsonPath('data.asset.identifier', 'PRN-0001')
        ->assertJsonPath('data.pc_unit', null);

    $ticket = Ticket::query()->latest('id')->firstOrFail();
    expect($ticket->asset_id)->toBe($this->printer->id)
        ->and($ticket->room_id)->toBe($this->room->id)
        ->and($ticket->pc_unit_id)->toBeNull();
});

it('takes the room from the asset when none is named', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'asset' => $this->printer->uuid,
    ]))->assertCreated()->assertJsonPath('data.location.room', 'Lab A');

    expect(Ticket::query()->latest('id')->value('room_id'))->toBe($this->room->id);
});

it('refuses a ticket naming both a computer and another item', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'room' => $this->room->uuid,
        'pc_unit' => $this->pc->uuid,
        'asset' => $this->printer->uuid,
    ]))->assertUnprocessable()->assertJsonValidationErrors('asset');
});

it('refuses a computer that is not in the named room', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'room' => $this->otherRoom->uuid,
        'pc_unit' => $this->pc->uuid,
    ]))->assertUnprocessable()->assertJsonValidationErrors('pc_unit');
});

it('refuses an asset that is not in the named room', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'room' => $this->otherRoom->uuid,
        'asset' => $this->printer->uuid,
    ]))->assertUnprocessable()->assertJsonValidationErrors('asset');
});

it('refuses a computer in a room that is out of use when no room is named', function () {
    // The ticket would inherit a room the room lookup hides — and that ticket
    // would then block the administrator from archiving it.
    $inactive = Room::factory()->create(['is_active' => false]);
    $pc = PcUnit::factory()->create(['room_id' => $inactive->id]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload(['pc_unit' => $pc->uuid]))
        ->assertUnprocessable()->assertJsonValidationErrors('pc_unit');
});

it('refuses an asset in a building that is out of use when no room is named', function () {
    $building = Building::factory()->create(['is_active' => false]);
    $room = Room::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $building->id])->id]);
    $printer = assetOfType(ComponentType::Printer, ['current_room_id' => $room->id, 'status' => AssetStatus::Deployed->value]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload(['asset' => $printer->uuid]))
        ->assertUnprocessable()->assertJsonValidationErrors('asset');
});

it('accepts an item that has no room at all', function () {
    $roomless = assetOfType(ComponentType::Projector, ['current_room_id' => null, 'status' => AssetStatus::Deployed->value]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload(['asset' => $roomless->uuid]))
        ->assertCreated()
        ->assertJsonPath('data.location', null);
});

it('accepts a computer already marked Needs Fix — a broken machine is what gets reported', function () {
    // Phase 2.11 (DD-100): no repair status makes a live PC unreportable;
    // only archiving does (next test).
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'status' => PcStatus::NeedsFix->value]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload(['pc_unit' => $pc->uuid]))
        ->assertCreated();
});

it('refuses an archived computer', function () {
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id]);
    $pc->delete();

    $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload(['pc_unit' => $pc->uuid]))
        ->assertUnprocessable()->assertJsonValidationErrors('pc_unit');
});

it('refuses equipment that is not in service, or not reportable', function () {
    $fitted = assetOfType(ComponentType::Monitor, ['current_room_id' => $this->room->id, 'status' => AssetStatus::Deployed->value]);
    PcComponentInstallation::factory()->create(['pc_unit_id' => $this->pc->id, 'asset_id' => $fitted->id]);

    $archived = assetOfType(ComponentType::Printer, ['current_room_id' => $this->room->id, 'status' => AssetStatus::Deployed->value]);
    $archived->delete();

    $refused = [
        'spare stock' => assetOfType(ComponentType::Printer, ['current_room_id' => $this->room->id, 'status' => AssetStatus::InStock->value]),
        'disposed' => assetOfType(ComponentType::Printer, ['current_room_id' => $this->room->id, 'status' => AssetStatus::Disposed->value]),
        'fitted inside a PC' => $fitted,
        'an internal part' => assetOfType(ComponentType::Ram, ['current_room_id' => $this->room->id, 'status' => AssetStatus::Deployed->value]),
        'archived' => $archived,
    ];

    foreach ($refused as $why => $asset) {
        $response = $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
            'room' => $this->room->uuid,
            'asset' => $asset->uuid,
        ]));

        expect($response->status())->toBe(422, $why);
        $response->assertJsonValidationErrors(['asset' => 'Choose an available item']);
    }

    expect(Ticket::query()->count())->toBe(0);
});

it('answers a malformed uuid with 422, never a database error', function () {
    foreach (['room', 'pc_unit', 'asset', 'reporter'] as $field) {
        $this->actingAs($this->admin)->postJson('/api/tickets', equipmentPayload([$field => 'not-a-uuid']))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
});

/* ------------------------------------------------------------- projections */

it('names the asset on every projection, as a label only on the community card', function () {
    $uuid = $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'room' => $this->room->uuid,
        'asset' => $this->printer->uuid,
    ]))->assertCreated()->json('data.id');

    $row = collect($this->actingAs($this->admin)->getJson('/api/admin/tickets')->assertOk()->json('data'))
        ->firstWhere('id', $uuid);
    expect($row['asset'])->toBe([
        'id' => $this->printer->uuid,
        'label' => $this->printer->displayName(),
        'identifier' => 'PRN-0001',
    ]);

    $card = $this->actingAs($this->otherTeacher)->getJson("/api/tickets/feed/{$uuid}")->assertOk();
    $card->assertJsonPath('data.asset', ['label' => $this->printer->displayName(), 'identifier' => 'PRN-0001']);

    foreach ([
        'feed card' => $card->json(),
        'by uuid' => $this->actingAs($this->otherTeacher)->getJson("/api/tickets/{$uuid}")->assertOk()->json(),
        'feed' => $this->actingAs($this->otherTeacher)->getJson('/api/tickets/feed')->assertOk()->json(),
        'mine' => $this->actingAs($this->teacher)->getJson('/api/tickets/mine')->assertOk()->json(),
    ] as $where => $body) {
        $encoded = (string) json_encode($body);

        // Present first, so an absence below cannot pass because the ticket
        // dropped out of a list.
        expect(str_contains($encoded, $uuid))->toBeTrue("{$where}: the ticket is listed")
            ->and(str_contains($encoded, $this->printer->uuid))->toBeFalse($where);
    }
});

it('gives the assigned technician the asset’s label, never its register data', function () {
    $ticket = ticketFor($this->teacher, 'assigned', ['room_id' => $this->room->id, 'asset_id' => $this->printer->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    $response = $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('data.asset.identifier', 'PRN-0001');

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain('SERIALPRINTERSECRET')
        ->and($encoded)->not->toContain('serial_number')
        ->and($encoded)->not->toContain('1234.56')
        ->and($encoded)->not->toContain('purchase')
        ->and($encoded)->not->toContain('warranty')
        ->and($encoded)->not->toContain('supplier');
});

it('names an asset by its archived catalog model on every ticket projection', function () {
    // Equipment bought under a catalog entry that was later archived stays
    // reportable, and the room lookup names it by that model (DD-78). The
    // ticket must not then fall back to the tag — label and identifier the
    // same string — on any surface that reads it.
    $model = $this->printer->hardwareModel()->firstOrFail();
    $model->delete();
    expect($model->trashed())->toBeTrue();

    $expected = ['label' => $model->model_name, 'identifier' => 'PRN-0001'];

    $created = $this->actingAs($this->teacher)->postJson('/api/tickets', equipmentPayload([
        'room' => $this->room->uuid,
        'asset' => $this->printer->uuid,
    ]))->assertCreated();
    $uuid = $created->json('data.id');

    assign(Ticket::query()->where('uuid', $uuid)->firstOrFail(), $this->technician, AssignmentStatus::Accepted);

    $records = [
        'as filed' => $created->json('data.asset'),
        'reporter, by uuid' => $this->actingAs($this->teacher)->getJson("/api/tickets/{$uuid}")->assertOk()->json('data.asset'),
        'administrator, by uuid' => $this->actingAs($this->admin)->getJson("/api/admin/tickets/{$uuid}")->assertOk()->json('data.asset'),
        'assigned technician' => $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$uuid}")->assertOk()->json('data.asset'),
    ];

    foreach ($records as $where => $asset) {
        expect($asset)->toMatchArray($expected, $where);
    }

    $lists = [
        'my tickets' => $this->actingAs($this->teacher)->getJson('/api/tickets/mine')->assertOk()->json('data'),
        'administrator directory' => $this->actingAs($this->admin)->getJson('/api/admin/tickets')->assertOk()->json('data'),
        'similar reports' => $this->actingAs($this->otherTeacher)
            ->getJson('/api/tickets/duplicates?'.http_build_query(['search' => 'printer jams']))
            ->assertOk()->json('data'),
    ];

    foreach ($lists as $where => $rows) {
        $row = collect($rows)->firstWhere('id', $uuid);

        expect($row)->not->toBeNull("{$where}: the ticket is listed")
            ->and($row['asset'])->toMatchArray($expected, $where);
    }
});

/* -------------------------------------------------------- similar reports */

it('ranks similar reports by their wording when no computer is chosen', function () {
    // The same-machine boost used to compare `pc_unit_id` with NULL when no PC
    // was chosen, which put every report *without* a PC — room-only and, since
    // Phase 2.9, every asset report — ahead of a closer match on a PC.
    $projector = assetOfType(ComponentType::Projector, ['current_room_id' => $this->room->id, 'status' => AssetStatus::Deployed->value]);

    $closer = ticketFor($this->teacher, 'open', [
        'pc_unit_id' => $this->pc->id,
        'title' => 'Screen flickers',
        'description' => 'The screen flickers, the screen flickers again and the screen keeps flickering.',
        // Older, so recency alone would put it second.
        'created_at' => now()->subHour(),
    ]);
    $weaker = ticketFor($this->teacher, 'open', [
        'asset_id' => $projector->id,
        'title' => 'Screen flickers',
        'description' => 'Reported once, before lunch.',
    ]);

    $ids = collect($this->actingAs($this->otherTeacher)
        ->getJson('/api/tickets/duplicates?'.http_build_query(['search' => 'screen flicker']))
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$closer->uuid, $weaker->uuid]);
});

it('still puts the chosen computer’s own reports first, whatever their wording', function () {
    $sameMachine = ticketFor($this->teacher, 'open', [
        'pc_unit_id' => $this->pc->id,
        'title' => 'Screen flickers',
        'description' => 'Reported once, before lunch.',
        'created_at' => now()->subHour(),
    ]);
    $closerElsewhere = ticketFor($this->teacher, 'open', [
        'room_id' => $this->room->id,
        'title' => 'Screen flickers',
        'description' => 'The screen flickers, the screen flickers again and the screen keeps flickering.',
    ]);

    $ids = collect($this->actingAs($this->otherTeacher)
        ->getJson('/api/tickets/duplicates?'.http_build_query(['search' => 'screen flicker', 'pc_unit' => $this->pc->uuid]))
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$sameMachine->uuid, $closerElsewhere->uuid]);
});

/* ---------------------------------------------------------- the database */

it('refuses a ticket row naming both a computer and an asset', function () {
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pc->id]);

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['asset_id' => $this->printer->id]))
        ->toThrow(QueryException::class, 'tickets_equipment_target_check');
});

it('keeps the ticket, without its link, when the asset is purged', function () {
    $ticket = ticketFor($this->teacher, 'open', ['asset_id' => $this->printer->id]);

    $this->printer->forceDelete();

    expect($ticket->fresh())->not->toBeNull()
        ->and($ticket->fresh()->asset_id)->toBeNull();
});

/* ------------------------------------------------ the asset's own records */

it('blocks archiving an asset while a ticket against it is open', function () {
    $ticket = ticketFor($this->teacher, 'open', ['asset_id' => $this->printer->id]);

    $this->actingAs($this->admin)->deleteJson("/api/admin/assets/{$this->printer->uuid}")
        ->assertUnprocessable()
        ->assertJsonPath('blockers.open_tickets', 1);

    $ticket->forceFill(['current_status_id' => ticketStatus('closed')->id])->save();

    $this->actingAs($this->admin)->deleteJson("/api/admin/assets/{$this->printer->uuid}")->assertOk();
});

it('shows the asset’s tickets on its timeline', function () {
    $ticket = ticketFor($this->teacher, 'open', ['asset_id' => $this->printer->id]);

    $entries = collect($this->actingAs($this->admin)->getJson("/api/admin/assets/{$this->printer->uuid}/history")
        ->assertOk()->json('data'));

    $entry = $entries->firstWhere('type', 'ticket');
    expect($entry)->not->toBeNull()
        ->and($entry['properties']['id'])->toBe($ticket->uuid);
});

it('lets the assigned technician start a repair record for the asset', function () {
    $ticket = ticketFor($this->teacher, 'in-progress', ['room_id' => $this->room->id, 'asset_id' => $this->printer->id]);
    assign($ticket, $this->technician, AssignmentStatus::InProgress);

    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.repair.can_start', true);

    $this->actingAs($this->technician)->postJson('/api/maintenance', [
        'title' => "Repair: {$ticket->ticket_number}",
        'type' => 'corrective',
        'asset' => $this->printer->uuid,
        'ticket' => $ticket->uuid,
    ])->assertCreated()->assertJsonPath('data.ticket.id', $ticket->uuid);
});

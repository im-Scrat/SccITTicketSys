<?php

declare(strict_types=1);

use App\Enums\TicketPrioritySource;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Phase 2.9 (owner decision D-B) — the deterministic half of location-aware
 * priority: an administrator gives a room a priority floor, and a ticket filed
 * there is created at no less than it. The AI half (raise-only) is covered in
 * tests/Feature/AI/AiPriorityRecommendationTest.php.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    // Creation dispatches the AI pre-screening; nothing here is about it.
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician');

    TicketCategory::query()->where('slug', 'hardware')
        ->update(['default_priority_id' => TicketPriority::query()->where('slug', 'low')->value('id')]);

    $this->room = Room::factory()->create();
});

function floorRoom(Room $room, ?string $slug): void
{
    $room->forceFill(['priority_floor_id' => $slug !== null ? TicketPriority::query()->where('slug', $slug)->value('id') : null])->save();
}

/** @param array<string, mixed> $extra */
function reportIn(mixed $test, Room $room, array $extra = [], mixed $actor = null): Ticket
{
    $test->actingAs($actor ?? $test->teacher)->postJson('/api/tickets', [
        'title' => 'Server will not boot',
        'description' => 'The rack server shows a blank screen after POST.',
        'category' => 'hardware',
        'room' => $room->uuid,
        ...$extra,
    ])->assertCreated();

    return Ticket::query()->latest('id')->firstOrFail()->load('priority');
}

/* -------------------------------------------------------- creation */

it('keeps the category default in a room with no floor', function () {
    $ticket = reportIn($this, $this->room);

    expect($ticket->priority->slug)->toBe('low')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Category);
});

it('raises a ticket to the room’s priority floor', function () {
    floorRoom($this->room, 'high');

    $ticket = reportIn($this, $this->room);

    expect($ticket->priority->slug)->toBe('high')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Location);
});

it('sets the SLA clock from the floor, not from the category default', function () {
    floorRoom($this->room, 'critical');

    $ticket = reportIn($this, $this->room);

    // Critical resolves in 480 minutes (seeded), low in 4320.
    expect((int) round($ticket->created_at->diffInMinutes($ticket->resolution_due_at)))->toBe(480);
});

it('never lowers a category default that is already above the floor', function () {
    TicketCategory::query()->where('slug', 'hardware')
        ->update(['default_priority_id' => TicketPriority::query()->where('slug', 'critical')->value('id')]);
    floorRoom($this->room, 'medium');

    $ticket = reportIn($this, $this->room);

    expect($ticket->priority->slug)->toBe('critical')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Category);
});

it('applies the floor of the room a chosen PC lives in', function () {
    floorRoom($this->room, 'high');
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'PC will not boot',
        'description' => 'Blank screen after the BIOS logo appears.',
        'category' => 'hardware',
        'pc_unit' => $pc->uuid,
    ])->assertCreated();

    expect(Ticket::query()->latest('id')->firstOrFail()->priority_id)
        ->toBe(TicketPriority::query()->where('slug', 'high')->value('id'));
});

it('records an explicit staff priority as manual and leaves it as chosen', function () {
    floorRoom($this->room, 'high');

    $ticket = reportIn($this, $this->room, ['priority' => 'medium'], $this->admin);

    expect($ticket->priority->slug)->toBe('medium')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Manual);
});

it('marks an administrator’s later priority change as manual', function () {
    $ticket = reportIn($this, $this->room);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/tickets/{$ticket->uuid}/priority", ['priority' => 'high'])
        ->assertOk()
        ->assertJsonPath('data.priority.source', 'manual');

    expect($ticket->refresh()->priority_source)->toBe(TicketPrioritySource::Manual);
});

/* --------------------------------------------------- room configuration */

function roomPayload(Room $room): array
{
    return [
        'name' => $room->name,
        'code' => $room->code,
        'room_type' => $room->room_type->value,
    ];
}

it('lets an administrator set and clear a room’s priority floor', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/rooms/{$this->room->uuid}", [...roomPayload($this->room), 'priority_floor' => 'high'])
        ->assertOk()
        ->assertJsonPath('data.priority_floor.slug', 'high');

    $this->actingAs($this->admin)
        ->putJson("/api/admin/rooms/{$this->room->uuid}", [...roomPayload($this->room), 'priority_floor' => null])
        ->assertOk()
        ->assertJsonPath('data.priority_floor', null);
});

it('leaves the floor untouched when an update does not mention it', function () {
    floorRoom($this->room, 'high');

    $this->actingAs($this->admin)
        ->putJson("/api/admin/rooms/{$this->room->uuid}", roomPayload($this->room))
        ->assertOk()
        ->assertJsonPath('data.priority_floor.slug', 'high');
});

it('rejects a priority floor that is not a known priority', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/admin/rooms/{$this->room->uuid}", [...roomPayload($this->room), 'priority_floor' => 'apocalyptic'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('priority_floor');
});

it('refuses a teacher configuring a priority floor', function () {
    $this->actingAs($this->teacher)
        ->putJson("/api/admin/rooms/{$this->room->uuid}", [...roomPayload($this->room), 'priority_floor' => 'critical'])
        ->assertForbidden();

    expect($this->room->fresh()->priority_floor_id)->toBeNull();
});

it('refuses a technician configuring a priority floor', function () {
    $this->actingAs($this->technician)
        ->putJson("/api/admin/rooms/{$this->room->uuid}", [...roomPayload($this->room), 'priority_floor' => 'critical'])
        ->assertForbidden();

    expect($this->room->fresh()->priority_floor_id)->toBeNull();
});

/* ------------------------------------------------------------ schema */

it('refuses an unknown priority source at the database level', function () {
    $ticket = ticketFor($this->teacher);

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['priority_source' => 'vibes']))
        ->toThrow(QueryException::class, 'tickets_priority_source_check');
});

it('clears a room’s floor, not the room, when a priority level is deleted', function () {
    $level = TicketPriority::query()->create([
        'name' => 'Temporary', 'slug' => 'temporary', 'level' => 99, 'color' => '#000000', 'is_active' => true,
    ]);
    $this->room->forceFill(['priority_floor_id' => $level->id])->save();

    $level->delete();

    expect($this->room->fresh())->not->toBeNull()
        ->and($this->room->fresh()->priority_floor_id)->toBeNull();
});

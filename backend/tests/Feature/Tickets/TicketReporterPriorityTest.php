<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\TicketPrioritySource;
use App\Models\ActivityLog;
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
 * Phase 2.9 — report-a-problem form (SDD DD-75; Client decision 2026-10-16):
 * a reporter may choose their own fault's priority.
 *
 * The order of authority this pins, weakest first:
 *   category default < reporter's choice < room floor < AI < IT staff (manual)
 * A reporter's choice is recorded as `reporter`; a room floor above it still
 * raises it (`location`, with what they asked for kept in the audit); IT
 * staff's own choice stays `manual` (TicketLocationPriorityTest). The AI half
 * — that `reporter` is raisable — is in AiPriorityRecommendationTest.
 *
 * Also pinned here: the options payload gives requesters the levels but not
 * their SLA minutes, a reporter never reads back `priority.source`, and the
 * migrated CHECK matches the enum — the guard against a forgotten migration.
 */

const PRIORITY_SOURCE_MIGRATION = '2026_10_16_100000_add_reporter_to_ticket_priority_source.php';

function prioritySourceMigration(): object
{
    return require database_path('migrations/'.PRIORITY_SOURCE_MIGRATION);
}

/** @param array<string, mixed> $extra */
function fileReport(mixed $test, array $extra = [], mixed $actor = null): Ticket
{
    $test->actingAs($actor ?? $test->teacher)->postJson('/api/tickets', [
        'title' => 'Projector flickers constantly',
        'description' => 'The projector flickers every few seconds during lessons.',
        'category' => 'hardware',
        'room' => $test->room->uuid,
        ...$extra,
    ])->assertCreated();

    return Ticket::query()->latest('id')->firstOrFail()->load('priority');
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    // Creation dispatches the AI pre-screening; nothing here is about it.
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    TicketCategory::query()->where('slug', 'hardware')
        ->update(['default_priority_id' => TicketPriority::query()->where('slug', 'low')->value('id')]);

    $this->room = Room::factory()->create();
});

/* -------------------------------------------------------------- creation */

it('records a teacher’s own choice as the reporter’s, and sets the SLA from it', function () {
    $ticket = fileReport($this, ['priority' => 'high']);

    expect($ticket->priority->slug)->toBe('high')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Reporter)
        // High resolves in 1440 minutes (seeded).
        ->and((int) round($ticket->created_at->diffInMinutes($ticket->resolution_due_at)))->toBe(1440);
});

it('raises a reporter’s choice to the room’s floor, and keeps what they asked for in the audit', function () {
    $this->room->forceFill(['priority_floor_id' => TicketPriority::query()->where('slug', 'high')->value('id')])->save();

    $ticket = fileReport($this, ['priority' => 'low']);

    expect($ticket->priority->slug)->toBe('high')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Location);

    $audit = ActivityLog::query()
        ->where('action', ActivityAction::TicketCreated->value)
        ->where('subject_id', $ticket->id)
        ->sole();

    expect($audit->properties['requested_priority'])->toBe('low')
        ->and($audit->properties['priority'])->toBe('high')
        ->and($audit->properties['priority_source'])->toBe('location');
});

it('leaves a reporter’s choice above the room’s floor as they chose it', function () {
    $this->room->forceFill(['priority_floor_id' => TicketPriority::query()->where('slug', 'high')->value('id')])->save();

    $ticket = fileReport($this, ['priority' => 'critical']);

    expect($ticket->priority->slug)->toBe('critical')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Reporter);

    $audit = ActivityLog::query()
        ->where('action', ActivityAction::TicketCreated->value)
        ->where('subject_id', $ticket->id)
        ->sole();
    expect($audit->properties)->not->toHaveKey('requested_priority');
});

it('keeps today’s rule when the reporter leaves it to the IT team', function () {
    $ticket = fileReport($this);

    expect($ticket->priority->slug)->toBe('low')
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Category);
});

it('refuses a priority that does not exist', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector flickers constantly',
        'description' => 'The projector flickers every few seconds during lessons.',
        'category' => 'hardware',
        'priority' => 'apocalyptic',
    ])->assertUnprocessable()->assertJsonValidationErrors('priority');
});

it('refuses a priority level that has been switched off', function () {
    TicketPriority::query()->where('slug', 'high')->update(['is_active' => false]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector flickers constantly',
        'description' => 'The projector flickers every few seconds during lessons.',
        'category' => 'hardware',
        'priority' => 'high',
    ])->assertUnprocessable()->assertJsonValidationErrors('priority');
});

it('still refuses a teacher reporting on behalf of someone else', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector flickers constantly',
        'description' => 'The projector flickers every few seconds during lessons.',
        'category' => 'hardware',
        'reporter' => userWithRole('teacher')->uuid,
    ])->assertUnprocessable()->assertJsonValidationErrors('reporter');
});

/* ---------------------------------------------------------------- options */

it('offers a teacher the priority levels, but never their SLA minutes', function () {
    $response = $this->actingAs($this->teacher)->getJson('/api/tickets/options')->assertOk();

    $priorities = $response->json('data.priorities');
    expect($priorities)->toHaveCount(4);

    foreach ($priorities as $priority) {
        expect(array_keys($priority))->toEqualCanonicalizing(['value', 'label', 'level', 'color']);
    }

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain('response_minutes')
        ->and($encoded)->not->toContain('resolution_minutes');
});

it('gives IT staff the SLA minutes with each level', function () {
    foreach (['administrator' => $this->admin, 'technician' => $this->technician] as $who => $user) {
        $priorities = $this->actingAs($user)->getJson('/api/tickets/options')->assertOk()->json('data.priorities');

        expect(collect($priorities)->firstWhere('value', 'high'))
            ->toMatchArray(['response_minutes' => 60, 'resolution_minutes' => 1440], $who);
    }
});

/* -------------------------------------------------------------- projection */

it('withholds the rule label from the reporter, not the raised level, and gives staff both', function () {
    // Withholding `source` only removes the explicit label (SDD DD-75). The
    // level itself still shows the floor — Low asked, High returned — as it
    // has since D-B: requesters see their ticket's priority by design, and
    // the floor is not treated as confidential. Both halves are pinned, so a
    // later change to either is a deliberate one.
    $this->room->forceFill(['priority_floor_id' => TicketPriority::query()->where('slug', 'high')->value('id')])->save();

    $created = $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector flickers constantly',
        'description' => 'The projector flickers every few seconds during lessons.',
        'category' => 'hardware',
        'room' => $this->room->uuid,
        'priority' => 'low',
    ])->assertCreated();
    $created->assertJsonPath('data.priority.slug', 'high')
        ->assertJsonMissingPath('data.priority.source');

    $uuid = $created->json('data.id');

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.priority.slug', 'high')
        ->assertJsonMissingPath('data.priority.source');

    $this->actingAs($this->admin)->getJson("/api/admin/tickets/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.priority.source', 'location');

    // The staff directory row carries it too, so triage can find the
    // priorities reporters chose.
    $row = collect($this->actingAs($this->admin)->getJson('/api/admin/tickets')->assertOk()->json('data'))
        ->firstWhere('id', $uuid);
    expect($row['priority']['source'])->toBe('location');
});

/* ------------------------------------------------------- the CHECK itself */

it('accepts reporter as a priority source at the database level', function () {
    $ticket = ticketFor($this->teacher);

    DB::table('tickets')->where('id', $ticket->id)->update(['priority_source' => 'reporter']);

    expect($ticket->refresh()->priority_source)->toBe(TicketPrioritySource::Reporter);
});

it('refuses reporter again once the migration is rolled back', function () {
    $ticket = ticketFor($this->teacher);

    prioritySourceMigration()->down();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['priority_source' => 'reporter']))
        ->toThrow(QueryException::class, 'tickets_priority_source_check');
});

it('turns reporter rows into unknown (NULL) on the way down, without touching updated_at', function () {
    $ticket = ticketFor($this->teacher);
    DB::table('tickets')->where('id', $ticket->id)->update([
        'priority_source' => 'reporter',
        'updated_at' => now()->subYear(),
    ]);
    $before = DB::table('tickets')->where('id', $ticket->id)->value('updated_at');

    prioritySourceMigration()->down();

    $row = DB::table('tickets')->where('id', $ticket->id)->first(['priority_source', 'updated_at']);
    expect($row->priority_source)->toBeNull()
        ->and($row->updated_at)->toBe($before);
});

it('accepts reporter again after a rollback and a re-run', function () {
    $ticket = ticketFor($this->teacher);

    prioritySourceMigration()->down();
    prioritySourceMigration()->up();

    DB::table('tickets')->where('id', $ticket->id)->update(['priority_source' => 'reporter']);

    expect($ticket->refresh()->priority_source)->toBe(TicketPrioritySource::Reporter);
});

it('keeps the migrated CHECK in step with the enum', function () {
    $definition = (string) DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'tickets_priority_source_check'",
    )->def;

    preg_match_all("/'([a-z_]+)'/", $definition, $matches);

    $allowed = collect($matches[1])->unique()->sort()->values()->all();
    $enum = collect(TicketPrioritySource::values())->sort()->values()->all();

    // On failure: production only ever widens this CHECK through migrations.
    // Add a NEW migration with frozen lists — never edit 2026_10_16_100000,
    // which has already run in production.
    expect($allowed)->toBe($enum, 'tickets_priority_source_check differs from TicketPrioritySource — add a NEW migration with a frozen list; never edit '.PRIORITY_SOURCE_MIGRATION);
});

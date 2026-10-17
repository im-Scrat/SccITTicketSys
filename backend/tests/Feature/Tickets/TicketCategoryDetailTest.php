<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Models\TicketCategory;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 2.9 — "Other" categories ask what kind of problem it is (SDD DD-77).
 *
 * Which categories ask is data (`ticket_categories.requires_detail`); the
 * answer is `tickets.category_detail`, 3–120 characters, never blank — the
 * FormRequests enforce the rule, `tickets_category_detail_check` backstops the
 * blank case. It is the reporter's own words, so it appears on the full record
 * and the staff list only, never on a community card or the reporter's own
 * list (which is rendered as community cards).
 */

const REQUIRES_DETAIL_MIGRATION = '2026_10_16_120000_add_requires_detail_to_ticket_categories.php';

/**
 * The categories a reporter can choose that ask for a description. Since
 * 2026-10-16 that is the single "Other": the group-level catch-alls are
 * retired (inactive), so they are no longer offered.
 */
const DETAIL_CATEGORY_SLUGS = ['other'];

/** The rows `2026_10_16_120000` flags — the retired pair included, so they ask again if ever switched back on. */
const DETAIL_MIGRATION_SLUGS = ['other-common', 'other-advanced', 'other'];

function requiresDetailMigration(): object
{
    return require database_path('migrations/'.REQUIRES_DETAIL_MIGRATION);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function otherPayload(array $extra = []): array
{
    return [
        'title' => 'Interactive board pen does nothing',
        'description' => 'The pen for the interactive board does not register any touch at all.',
        'category' => 'other',
        ...$extra,
    ];
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    // Creation dispatches the AI pre-screening; nothing here is about it.
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');

    $this->otherId = (int) TicketCategory::query()->where('slug', 'other')->value('id');
});

/* ------------------------------------------------------------- vocabulary */

it('offers exactly one category that asks for a description: "Other"', function () {
    $categories = $this->actingAs($this->teacher)->getJson('/api/tickets/options')->assertOk()->json('data.categories');

    expect(collect($categories)->where('requires_detail', true)->pluck('value')->values()->all())
        ->toBe(DETAIL_CATEGORY_SLUGS)
        ->and(collect($categories)->every(fn (array $c): bool => is_bool($c['requires_detail'])))->toBeTrue();
});

/* --------------------------------------------------------------- creation */

it('requires a description under an "Other" category', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload(['category_detail' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors('category_detail');
});

it('treats a description of only spaces as no description', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload(['category_detail' => '    ']))
        ->assertUnprocessable()->assertJsonValidationErrors('category_detail');
});

it('refuses a description that is too short or too long', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload(['category_detail' => 'ab']))
        ->assertUnprocessable()->assertJsonValidationErrors('category_detail');

    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload(['category_detail' => str_repeat('x', 121)]))
        ->assertUnprocessable()->assertJsonValidationErrors('category_detail');
});

it('refuses a description under a category that does not ask for one', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload([
        'category' => 'hardware',
        'category_detail' => 'Smart board pen',
    ]))->assertUnprocessable()->assertJsonValidationErrors('category_detail');
});

it('tells a page loaded before this rule to reload, on a field it can show', function () {
    // An old form sends no `category_detail` key at all and has no field to
    // show its error under — so the message goes on `category`.
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category' => 'reload the page'])
        ->assertJsonMissingValidationErrors('category_detail');
});

it('stores the description trimmed', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload(['category_detail' => '   Smart board pen  ']))
        ->assertCreated()
        ->assertJsonPath('data.category.detail', 'Smart board pen');

    expect(Ticket::query()->latest('id')->value('category_detail'))->toBe('Smart board pen');
});

it('accepts "Other" with a description, and links it to the "Other" category', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', otherPayload([
        'category_detail' => 'Classroom clock',
    ]))->assertCreated()
        ->assertJsonPath('data.category.slug', 'other')
        ->assertJsonPath('data.category.detail', 'Classroom clock');
});

/* ------------------------------------------------------------- projection */

it('shows the description on the full record and the staff list', function () {
    $uuid = $this->actingAs($this->teacher)
        ->postJson('/api/tickets', otherPayload(['category_detail' => 'DETAILMARKER pen']))
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$uuid}")
        ->assertOk()->assertJsonPath('data.category.detail', 'DETAILMARKER pen');

    $this->actingAs($this->admin)->getJson("/api/admin/tickets/{$uuid}")
        ->assertOk()->assertJsonPath('data.category.detail', 'DETAILMARKER pen');

    $row = collect($this->actingAs($this->admin)->getJson('/api/admin/tickets')->assertOk()->json('data'))
        ->firstWhere('id', $uuid);
    expect($row['category']['detail'])->toBe('DETAILMARKER pen');
});

it('never puts the description on a community card or the reporter’s own list', function () {
    $uuid = $this->actingAs($this->teacher)
        ->postJson('/api/tickets', otherPayload(['category_detail' => 'DETAILMARKER pen']))
        ->assertCreated()
        ->json('data.id');

    $bodies = [
        'feed card, another teacher' => $this->actingAs($this->otherTeacher)->getJson("/api/tickets/feed/{$uuid}")->assertOk()->json(),
        'feed card, the reporter' => $this->actingAs($this->teacher)->getJson("/api/tickets/feed/{$uuid}")->assertOk()->json(),
        'by uuid, another teacher' => $this->actingAs($this->otherTeacher)->getJson("/api/tickets/{$uuid}")->assertOk()->json(),
        'my tickets' => $this->actingAs($this->teacher)->getJson('/api/tickets/mine')->assertOk()->json(),
        'feed' => $this->actingAs($this->otherTeacher)->getJson('/api/tickets/feed')->assertOk()->json(),
    ];

    foreach ($bodies as $where => $body) {
        $encoded = (string) json_encode($body);

        // Present first, so an absence below cannot pass because the ticket
        // dropped out of a list. `toContain` is variadic, so the route goes in
        // a message here.
        expect(str_contains($encoded, $uuid))->toBeTrue("{$where}: the ticket is listed")
            ->and(str_contains($encoded, 'DETAILMARKER'))->toBeFalse($where);
    }
});

/* ------------------------------------------------------------------ edits */

it('still saves a legacy "Other" ticket that never had a description', function () {
    $ticket = ticketFor($this->teacher, 'open', [
        'category_id' => TicketCategory::query()->where('slug', 'other')->value('id'),
    ]);

    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}", ['title' => 'A clearer title for it'])
        ->assertOk();

    expect($ticket->fresh()->title)->toBe('A clearer title for it')
        ->and($ticket->fresh()->category_detail)->toBeNull();
});

it('requires a description when an edit moves a ticket into "Other"', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}", ['category' => 'other', 'category_detail' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('category_detail');

    // An old drawer sends no key at all: told to reload, on a field it shows.
    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}", ['category' => 'other'])
        ->assertUnprocessable()->assertJsonValidationErrors(['category' => 'reload the page']);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}", ['category' => 'other', 'category_detail' => 'Kernel module'])
        ->assertOk()
        ->assertJsonPath('data.category.slug', 'other')
        ->assertJsonPath('data.category.detail', 'Kernel module');
});

it('refuses an edit that would erase a description the category still asks for', function () {
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => $this->otherId, 'category_detail' => 'Smart board pen']);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}", ['category_detail' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('category_detail');

    expect($ticket->fresh()->category_detail)->toBe('Smart board pen');
});

it('drops the description when an edit moves the ticket out of "Other"', function () {
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => $this->otherId, 'category_detail' => 'Smart board pen']);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}", ['category' => 'hardware'])
        ->assertOk()
        ->assertJsonPath('data.category.detail', null);

    expect($ticket->fresh()->category_detail)->toBeNull();
});

it('saves an edit that changes only the description', function () {
    // `UpdateTicket` returns early when nothing in its attribute list changed;
    // the description must be part of that list, or this edit is lost.
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => $this->otherId, 'category_detail' => 'Smart board pen']);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}", ['category_detail' => 'Smart board eraser'])
        ->assertOk()
        ->assertJsonPath('data.category.detail', 'Smart board eraser');

    expect($ticket->fresh()->category_detail)->toBe('Smart board eraser');
});

/* --------------------------------------------------------- the database */

it('refuses a description of spaces at the database level', function () {
    $ticket = ticketFor($this->teacher);

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['category_detail' => '   ']))
        ->toThrow(QueryException::class, 'tickets_category_detail_check');
});

it('refuses a description of tabs and newlines at the database level', function () {
    // `btrim` would let this through; the POSIX class does not.
    $ticket = ticketFor($this->teacher);

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['category_detail' => "\t\n"]))
        ->toThrow(QueryException::class, 'tickets_category_detail_check');
});

/* -------------------------------------------------------------- migration */

it('flags exactly the three categories, and running it again changes nothing', function () {
    DB::table('ticket_categories')->update(['requires_detail' => false]);

    requiresDetailMigration()->up();
    $once = TicketCategory::query()->where('requires_detail', true)->pluck('slug')->all();

    requiresDetailMigration()->up();

    // Compared as sets: the retired pair keep the sort order the 10_13
    // migration gave them, which interleaves with the seeded rows.
    expect($once)->toEqualCanonicalizing(DETAIL_MIGRATION_SLUGS)
        ->and(TicketCategory::query()->where('requires_detail', true)->pluck('slug')->all())
        ->toEqualCanonicalizing(DETAIL_MIGRATION_SLUGS);
});

it('drops the column on the way down', function () {
    requiresDetailMigration()->down();

    expect(Schema::hasColumn('ticket_categories', 'requires_detail'))->toBeFalse();

    requiresDetailMigration()->up();

    expect(Schema::hasColumn('ticket_categories', 'requires_detail'))->toBeTrue()
        ->and(TicketCategory::query()->where('requires_detail', true)->pluck('slug')->all())
        ->toEqualCanonicalizing(DETAIL_MIGRATION_SLUGS);
});

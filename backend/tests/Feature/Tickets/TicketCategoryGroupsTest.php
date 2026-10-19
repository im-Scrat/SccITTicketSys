<?php

declare(strict_types=1);

use App\Models\TicketCategory;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * The category vocabulary behind the ticket filter's two groups — "Common
 * Problems" and "Advanced / Uncommon Problems" (SRS FR-TKT-001/014).
 *
 * The grouping itself is a frontend concern, keyed on these slugs; what the
 * backend owes it is that every slug exists, carries the exact label the filter
 * shows, is accepted by the list endpoints, and actually narrows the result. The
 * migration tests pin the part that is easy to get dangerously wrong: adding rows
 * to a database that is already in use.
 */

const CATEGORY_MIGRATION = '2026_10_13_100000_add_common_and_advanced_ticket_categories.php';

const RETIRE_MIGRATION = '2026_10_16_150000_retire_other_common_and_other_advanced_categories.php';

/**
 * slug => label of every category a person can choose, in the order the filter
 * presents them. Since 2026-10-16 there is one "Other" (Client decision): the
 * group-level "Other Common Issues" / "Other Advanced Issues" are retired.
 */
const CATEGORY_VOCABULARY = [
    'hardware' => 'Hardware',
    'software' => 'Software',
    'network' => 'Network',
    'performance-slowness' => 'Performance & System Slowness',
    'os-updates' => 'Operating System Updates',
    'power-issue' => 'Power Issue',
    'audio-video-output' => 'Audio & Video Output',
    'browser-web' => 'Browser & Web Issues',
    'peripheral' => 'Peripheral',
    'account-access' => 'Account & Access',
    'bios-firmware' => 'BIOS / Firmware errors',
    'blue-screen' => 'Blue Screen / Kernel Panic',
    'missing-software' => 'Missing Software',
    'other' => 'Other',
];

/** The two group-level catch-alls switched off by {@see RETIRE_MIGRATION}. */
const RETIRED_CATEGORIES = [
    'other-common' => 'Other Common Issues',
    'other-advanced' => 'Other Advanced Issues',
];

/**
 * What `2026_10_13_100000` itself produces — it still inserts the two
 * catch-alls (active); a later migration retires them. Its own tests pin
 * this, because a migration must keep meaning what it meant when it ran.
 */
const GROUP_MIGRATION_VOCABULARY = [
    'hardware' => 'Hardware',
    'software' => 'Software',
    'network' => 'Network',
    'performance-slowness' => 'Performance & System Slowness',
    'os-updates' => 'Operating System Updates',
    'power-issue' => 'Power Issue',
    'audio-video-output' => 'Audio & Video Output',
    'browser-web' => 'Browser & Web Issues',
    'other-common' => 'Other Common Issues',
    'peripheral' => 'Peripheral',
    'account-access' => 'Account & Access',
    'bios-firmware' => 'BIOS / Firmware errors',
    'blue-screen' => 'Blue Screen / Kernel Panic',
    'missing-software' => 'Missing Software',
    'other-advanced' => 'Other Advanced Issues',
    'other' => 'Other',
];

/** The ten rows the 10_13 migration introduces. */
const CATEGORIES_ADDED = [
    'performance-slowness', 'os-updates', 'power-issue', 'audio-video-output', 'browser-web',
    'other-common', 'bios-firmware', 'blue-screen', 'missing-software', 'other-advanced',
];

function categoryMigration(): object
{
    return require database_path('migrations/'.CATEGORY_MIGRATION);
}

function retireMigration(): object
{
    return require database_path('migrations/'.RETIRE_MIGRATION);
}

function categoryId(string $slug): int
{
    return (int) TicketCategory::query()->where('slug', $slug)->value('id');
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
});

/* ---------------------------------------------------------------- vocabulary */

it('offers every category, labelled and ordered as the filter groups expect', function () {
    $expected = collect(CATEGORY_VOCABULARY)->map(fn ($label, $slug) => ['value' => $slug, 'label' => $label])->values();

    foreach (['teacher' => $this->teacher, 'admin' => $this->admin] as $who => $user) {
        $categories = $this->actingAs($user)->getJson('/api/tickets/options')->assertOk()->json('data.categories');

        expect(collect($categories)->map(fn ($c) => ['value' => $c['value'], 'label' => $c['label']])->all())
            ->toEqual($expected->all(), "{$who} sees a different category list");
    }
});

it('seeds the same vocabulary a migrated database ends up with', function () {
    expect(TicketCategory::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'slug')->all())
        ->toBe(CATEGORY_VOCABULARY);

    // The seed must not switch the retired pair back on: it forces every row
    // it names to active, so it no longer names them.
    expect(TicketCategory::query()->whereIn('slug', array_keys(RETIRED_CATEGORIES))->pluck('is_active', 'slug')->all())
        ->toEqual(['other-common' => false, 'other-advanced' => false]);
});

it('offers a single "Other", and it is the only category that asks for a description', function () {
    $categories = collect($this->actingAs($this->teacher)->getJson('/api/tickets/options')
        ->assertOk()->json('data.categories'));

    expect($categories->filter(fn ($c) => str_starts_with($c['value'], 'other'))->pluck('label')->all())
        ->toBe(['Other'])
        ->and($categories->where('requires_detail', true)->pluck('value')->values()->all())->toBe(['other']);
});

/* ------------------------------------------------------------------- filters */

it('filters the requester’s own list by each category and returns only that ticket', function () {
    $tickets = collect(array_keys(CATEGORY_VOCABULARY))->mapWithKeys(fn (string $slug) => [
        $slug => ticketFor($this->teacher, 'open', ['category_id' => categoryId($slug)]),
    ]);

    foreach ($tickets as $slug => $ticket) {
        $rows = $this->actingAs($this->teacher)->getJson("/api/tickets/mine?category={$slug}")
            ->assertOk()->json('data');

        expect(collect($rows)->pluck('id')->all())->toBe([$ticket->uuid], "category {$slug}");
        expect($rows[0]['category']['slug'])->toBe($slug);
        expect($rows[0]['category']['label'])->toBe(CATEGORY_VOCABULARY[$slug]);
    }
});

it('filters the administrator directory by each category and returns only that ticket', function () {
    $tickets = collect(array_keys(CATEGORY_VOCABULARY))->mapWithKeys(fn (string $slug) => [
        $slug => ticketFor($this->teacher, 'open', ['category_id' => categoryId($slug)]),
    ]);

    foreach ($tickets as $slug => $ticket) {
        $rows = $this->actingAs($this->admin)->getJson("/api/admin/tickets?category={$slug}")
            ->assertOk()->json('data');

        expect(collect($rows)->pluck('id')->all())->toBe([$ticket->uuid], "category {$slug}");
    }
});

it('accepts every category slug on the community feed instead of rejecting it', function () {
    foreach (array_keys(CATEGORY_VOCABULARY) as $slug) {
        $this->actingAs($this->teacher)->getJson("/api/tickets/feed?category={$slug}")->assertOk();
    }
});

it('accepts a comma-separated list of categories, and rejects an unknown one', function () {
    ticketFor($this->teacher, 'open', ['category_id' => categoryId('power-issue')]);
    ticketFor($this->teacher, 'open', ['category_id' => categoryId('blue-screen')]);
    ticketFor($this->teacher, 'open', ['category_id' => categoryId('network')]);

    $both = $this->actingAs($this->teacher)->getJson('/api/tickets/mine?category=power-issue,blue-screen')
        ->assertOk()->json('data');
    expect(collect($both)->pluck('category.slug')->sort()->values()->all())->toBe(['blue-screen', 'power-issue']);

    // A slug the filter chip cannot back is refused, never silently dropped.
    $this->actingAs($this->teacher)->getJson('/api/tickets/mine?category=not-a-category')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category');
});

/* ------------------------------------------------------------------ creation */

it('lets a requester report a problem under a new category', function () {
    foreach (['power-issue', 'bios-firmware', 'other'] as $slug) {
        $this->actingAs($this->teacher)->postJson('/api/tickets', [
            'title' => "Fault filed under {$slug}",
            'description' => 'The machine does something it should not, and it started this morning.',
            'category' => $slug,
            // "Other" asks what kind of problem it is (Phase 2.9, SDD DD-77;
            // TicketCategoryDetailTest).
            ...($slug === 'other' ? ['category_detail' => 'Driver signing error'] : []),
        ])->assertCreated()->assertJsonPath('data.category.slug', $slug);
    }

    // No default priority is configured for the new rows, so the fallback is the
    // middle of the scale — never the most urgent.
    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Another fault',
        'description' => 'The machine does something it should not, and it started this morning.',
        'category' => 'audio-video-output',
    ])->assertCreated()->assertJsonPath('data.priority.slug', 'medium');
});

it('rejects a ticket filed under a retired catch-all', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Fault filed under a retired category',
        'description' => 'The machine does something it should not, and it started this morning.',
        'category' => 'other-common',
        'category_detail' => 'Driver signing error',
    ])->assertUnprocessable()->assertJsonValidationErrors('category');
});

it('still finds a ticket filed under a retired catch-all when filtering by it', function () {
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => categoryId('other-advanced')]);

    $rows = $this->actingAs($this->teacher)->getJson('/api/tickets/mine?category=other-advanced')
        ->assertOk()->json('data');

    expect(collect($rows)->pluck('id')->all())->toBe([$ticket->uuid])
        ->and($rows[0]['category']['label'])->toBe('Other Advanced Issues');
});

it('rejects a ticket filed under a category that does not exist', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Fault filed under nothing',
        'description' => 'The machine does something it should not, and it started this morning.',
        'category' => 'not-a-category',
    ])->assertUnprocessable()->assertJsonValidationErrors('category');
});

/* ----------------------------------------------------------------- migration */

it('adds the ten new categories to a database that predates them', function () {
    // Put the table back the way an installed database looked before this change.
    TicketCategory::query()->whereIn('slug', CATEGORIES_ADDED)->delete();
    foreach (['hardware', 'software', 'network', 'peripheral', 'account-access', 'other'] as $i => $slug) {
        TicketCategory::query()->where('slug', $slug)->update(['sort_order' => $i]);
    }
    expect(TicketCategory::query()->count())->toBe(6);

    categoryMigration()->up();

    expect(TicketCategory::query()->orderBy('sort_order')->pluck('name', 'slug')->all())
        ->toBe(GROUP_MIGRATION_VOCABULARY);
    expect(TicketCategory::query()->whereIn('slug', CATEGORIES_ADDED)->where('is_active', true)->count())->toBe(10);
});

it('is idempotent: running it again changes nothing', function () {
    TicketCategory::query()->whereIn('slug', CATEGORIES_ADDED)->delete();

    categoryMigration()->up();
    $once = TicketCategory::query()->orderBy('id')->get(['slug', 'name', 'sort_order'])->toArray();

    categoryMigration()->up();
    categoryMigration()->up();

    expect(TicketCategory::query()->orderBy('id')->get(['slug', 'name', 'sort_order'])->toArray())->toBe($once)
        ->and(TicketCategory::query()->count())->toBe(16);
});

it('never overwrites what an administrator set on an existing category', function () {
    TicketCategory::query()->whereIn('slug', CATEGORIES_ADDED)->delete();
    TicketCategory::query()->where('slug', 'hardware')->update(['is_active' => false]);
    TicketCategory::query()->where('slug', 'software')->update(['name' => 'Software & Apps']);
    // Someone already created one of the new categories by hand, under their own name.
    TicketCategory::query()->create(['slug' => 'power-issue', 'name' => 'Power problems', 'is_active' => false, 'sort_order' => 99]);

    categoryMigration()->up();

    expect(TicketCategory::query()->where('slug', 'hardware')->value('is_active'))->toBeFalse()
        ->and(TicketCategory::query()->where('slug', 'software')->value('name'))->toBe('Software & Apps')
        ->and(TicketCategory::query()->where('slug', 'power-issue')->value('name'))->toBe('Power problems')
        ->and(TicketCategory::query()->where('slug', 'power-issue')->value('is_active'))->toBeFalse();
});

it('does not seed the six original categories into a database that has not been seeded', function () {
    // The trap: factories (and the first-install seeder) create these by name, and
    // the name column is unique. A migration that pre-created them would collide.
    TicketCategory::query()->delete();

    categoryMigration()->up();

    expect(TicketCategory::query()->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['performance-slowness', 'os-updates', 'power-issue', 'audio-video-output', 'browser-web',
            'other-common', 'bios-firmware', 'blue-screen', 'missing-software', 'other-advanced'])
        ->and(TicketCategory::query()->whereIn('slug', ['hardware', 'other'])->exists())->toBeFalse();
});

it('rolls back without failing on a category that tickets already use', function () {
    ticketFor($this->teacher, 'open', ['category_id' => categoryId('power-issue')]);

    categoryMigration()->down();

    // `tickets.category_id` is RESTRICT: the used row stays, the unused ones go.
    expect(TicketCategory::query()->where('slug', 'power-issue')->exists())->toBeTrue()
        ->and(TicketCategory::query()->whereIn('slug', array_diff(CATEGORIES_ADDED, ['power-issue']))->exists())->toBeFalse()
        ->and(TicketCategory::query()->whereIn('slug', ['hardware', 'software', 'network', 'peripheral', 'account-access', 'other'])
            ->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['hardware', 'software', 'network', 'peripheral', 'account-access', 'other']);
});

/* ----------------------------------------- retiring the group catch-alls */

it('switches the two group catch-alls off, and nothing else', function () {
    // As production stood before the change: both catch-alls offered.
    TicketCategory::query()->whereIn('slug', array_keys(RETIRED_CATEGORIES))->update(['is_active' => true]);
    $before = TicketCategory::query()->whereNotIn('slug', array_keys(RETIRED_CATEGORIES))
        ->orderBy('id')->get(['slug', 'name', 'is_active', 'sort_order', 'requires_detail'])->toArray();

    retireMigration()->up();

    expect(TicketCategory::query()->whereIn('slug', array_keys(RETIRED_CATEGORIES))->where('is_active', true)->exists())->toBeFalse()
        ->and(TicketCategory::query()->whereNotIn('slug', array_keys(RETIRED_CATEGORIES))
            ->orderBy('id')->get(['slug', 'name', 'is_active', 'sort_order', 'requires_detail'])->toArray())->toBe($before);
});

it('keeps tickets filed under a retired catch-all, and is idempotent', function () {
    TicketCategory::query()->whereIn('slug', array_keys(RETIRED_CATEGORIES))->update(['is_active' => true]);
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => categoryId('other-common')]);

    retireMigration()->up();
    retireMigration()->up();

    expect($ticket->fresh()->category?->slug)->toBe('other-common')
        ->and(TicketCategory::query()->whereIn('slug', array_keys(RETIRED_CATEGORIES))->where('is_active', false)->count())->toBe(2);
});

it('rolls back by switching the two catch-alls on again', function () {
    retireMigration()->down();

    expect(TicketCategory::query()->whereIn('slug', array_keys(RETIRED_CATEGORIES))->where('is_active', true)->count())->toBe(2)
        ->and(TicketCategory::query()->where('slug', 'other')->value('is_active'))->toBeTrue();
});

/* ------------------------------------------------- editing a retired one */

it('still saves an edit to a ticket whose category was retired, if the category is left alone', function () {
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => categoryId('other-common')]);

    // The edit drawer always sends the category it shows.
    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}", [
        'title' => 'Corrected title for the retired category',
        'category' => 'other-common',
    ])->assertOk()->assertJsonPath('data.title', 'Corrected title for the retired category')
        ->assertJsonPath('data.category.slug', 'other-common');
});

it('refuses to move a ticket into a retired category', function () {
    $ticket = ticketFor($this->teacher, 'open', ['category_id' => categoryId('hardware')]);

    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}", [
        'category' => 'other-advanced',
        'category_detail' => 'Driver signing error',
    ])->assertUnprocessable()->assertJsonValidationErrors('category');
});

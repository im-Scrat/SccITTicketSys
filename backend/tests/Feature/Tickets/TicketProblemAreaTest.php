<?php

declare(strict_types=1);

use App\Enums\ProblemArea;
use App\Models\TicketCategory;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 — each ticket category's problem area (owner decision D6, SDD
 * DD-87): the approved mapping, held equal in the migration and the seeder,
 * and a database CHECK that matches the enum.
 */

const PROBLEM_AREA_MIGRATION = '2026_10_18_100000_add_problem_area_to_ticket_categories';

it('seeds every category with the mapping the owner approved', function (): void {
    $this->seed(TicketLookupSeeder::class);

    $mapping = (new ReflectionClassConstant(ReportFixtures::migration(PROBLEM_AREA_MIGRATION), 'MAPPING'))->getValue();
    $seeded = TicketCategory::query()->get()->mapWithKeys(fn (TicketCategory $c): array => [$c->slug => $c->problem_area->value])->all();

    foreach ($seeded as $slug => $area) {
        expect($mapping)->toHaveKey($slug)
            ->and($area)->toBe($mapping[$slug], "{$slug} is seeded as {$area}, migration says {$mapping[$slug]}");
    }

    // 14 seeded plus the two retired catch-alls 2026_10_13_100000 inserts.
    expect($seeded)->toHaveCount(16)
        ->and($seeded['blue-screen'])->toBe('software')
        ->and($seeded['power-issue'])->toBe('hardware')
        ->and($seeded['account-access'])->toBe('other')
        ->and($seeded['other-common'])->toBe('other')
        ->and($seeded['other-advanced'])->toBe('other');
});

it('maps an installed database in place, keeping an area that was already set', function (): void {
    $this->seed(TicketLookupSeeder::class);
    $migration = ReportFixtures::migration(PROBLEM_AREA_MIGRATION);

    // An installed database before this migration: the column absent, then
    // (as if half-applied) present with one area set by hand.
    $migration->down();
    Schema::table('ticket_categories', fn (Blueprint $table) => $table->string('problem_area', 16)->nullable());
    DB::table('ticket_categories')->where('slug', 'network')->update(['problem_area' => 'other']);

    $migration->up();

    expect(TicketCategory::query()->where('slug', 'network')->value('problem_area'))->toBe(ProblemArea::Other)
        ->and(TicketCategory::query()->where('slug', 'hardware')->value('problem_area'))->toBe(ProblemArea::Hardware)
        ->and(TicketCategory::query()->whereNull('problem_area')->count())->toBe(0);
});

it('keeps the CHECK list and the enum equal', function (): void {
    $areas = (new ReflectionClassConstant(ReportFixtures::migration(PROBLEM_AREA_MIGRATION), 'AREAS'))->getValue();

    expect($areas)->toBe(ProblemArea::values());
});

it('refuses an area outside the domain at the database', function (): void {
    $this->seed(TicketLookupSeeder::class);

    expect(fn () => DB::table('ticket_categories')->where('slug', 'hardware')->update(['problem_area' => 'furniture']))
        ->toThrow(QueryException::class, 'ticket_categories_problem_area_check');
});

<?php

declare(strict_types=1);

use App\Enums\PcStatus;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use Database\Seeders\MaintenanceTypeSeeder;

/**
 * The floor-plan slice of the browser fixtures (WP-C).
 *
 * The map is audited for accessibility with a unit in every status, so the
 * fixture has to keep providing that — and, like the rest of the command, has
 * to be idempotent, because the runner re-seeds before every run.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
});

it('gives the fixture room an active layout with a unit in every PC status', function (): void {
    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    $room = Room::query()->where('code', 'E2E-LAB-01')->firstOrFail();
    $layout = RoomLayout::query()->where('room_id', $room->id)->where('is_active', true)->firstOrFail();

    $statuses = PcUnit::query()
        ->where('unit_code', 'like', 'E2E-FP-%')
        ->whereIn('id', FloorPlanPosition::query()->where('room_layout_id', $layout->id)->pluck('pc_unit_id'))
        ->pluck('status')
        ->map(fn ($status) => $status instanceof PcStatus ? $status->value : (string) $status)
        ->sort()->values()->all();

    expect($statuses)->toBe(collect(PcStatus::values())->sort()->values()->all())
        ->and($layout->width)->toBe(1000);
});

it('does not grow the floor plan when re-seeded', function (): void {
    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();
    $counts = [RoomLayout::query()->count(), FloorPlanPosition::query()->count(), PcUnit::query()->count()];

    $this->artisan('sccit:e2e-fixtures')->assertSuccessful();

    expect([RoomLayout::query()->count(), FloorPlanPosition::query()->count(), PcUnit::query()->count()])->toBe($counts);
});

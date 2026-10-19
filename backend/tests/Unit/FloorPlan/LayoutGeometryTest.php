<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Services\LayoutGeometry;

/**
 * Phase 2.11 — the Floorplan's geometry rules (SDD DD-102). The client mirror
 * (`features/floor-plan/lib/geometry.test.ts`) asserts the same cases.
 */
it('gives a workstation a 40-unit square footprint centred on its point', function (): void {
    expect(LayoutGeometry::workstationBox(100, 60))->toBe([80.0, 40.0, 120.0, 80.0]);
});

it('keeps a workstation that touches the walls inside the room', function (): void {
    expect(LayoutGeometry::boxInsideRoom(LayoutGeometry::workstationBox(20, 20), 1000, 600))->toBeTrue()
        ->and(LayoutGeometry::boxInsideRoom(LayoutGeometry::workstationBox(980, 580), 1000, 600))->toBeTrue();
});

it('puts a workstation that crosses a wall outside the room', function (): void {
    expect(LayoutGeometry::boxInsideRoom(LayoutGeometry::workstationBox(19, 100), 1000, 600))->toBeFalse()
        ->and(LayoutGeometry::boxInsideRoom(LayoutGeometry::workstationBox(500, 581), 1000, 600))->toBeFalse();
});

it('treats boxes that share only an edge as not overlapping', function (): void {
    expect(LayoutGeometry::boxesOverlap(
        LayoutGeometry::workstationBox(100, 100),
        LayoutGeometry::workstationBox(140, 100),
    ))->toBeFalse();
});

it('treats boxes that share any area as overlapping', function (): void {
    expect(LayoutGeometry::boxesOverlap(
        LayoutGeometry::workstationBox(100, 100),
        LayoutGeometry::workstationBox(139, 120),
    ))->toBeTrue();
});

it('turns a rectangle about its centre, clockwise like SVG', function (): void {
    $corners = LayoutGeometry::corners(100, 100, 40, 20, 90);
    $rounded = array_map(fn (array $p): array => [round($p[0], 6), round($p[1], 6)], $corners);

    // A 40×20 rectangle turned a quarter becomes 20 wide and 40 tall.
    expect($rounded)->toEqualCanonicalizing([[110.0, 80.0], [110.0, 120.0], [90.0, 120.0], [90.0, 80.0]]);
});

it('finds a rotated fixture corner that leaves the room', function (): void {
    // A 200-long wall at 45° near the top edge pokes above y = 0.
    $corners = LayoutGeometry::corners(100, 20, 200, 8, 45);

    expect(LayoutGeometry::pointsInsideRoom($corners, 1000, 600))->toBeFalse();
});

it('refuses a point that is not a finite number', function (): void {
    expect(LayoutGeometry::pointsInsideRoom([[NAN, 10.0]], 1000, 600))->toBeFalse()
        ->and(LayoutGeometry::pointsInsideRoom([[INF, 10.0]], 1000, 600))->toBeFalse();
});

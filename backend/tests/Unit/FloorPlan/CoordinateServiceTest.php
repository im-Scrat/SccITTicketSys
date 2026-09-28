<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Services\CoordinateService;

/**
 * WP-B — the coordinate maths. Pure: no database, no framework state.
 *
 * The server is authoritative for placement, so these cases pin the behaviour
 * a client-side preview has to agree with — chiefly the rounding direction at
 * the half-way point and the order of snap-then-clamp.
 */
beforeEach(function (): void {
    $this->coords = new CoordinateService;
});

it('snaps to the nearest grid line', function (float $in, int $grid, float $expected): void {
    expect($this->coords->snap($in, $grid))->toBe($expected);
})->with([
    'already on a line' => [40.0, 20, 40.0],
    'just under half' => [29.99, 20, 20.0],
    'exactly half rounds up' => [30.0, 20, 40.0],
    'just over half' => [30.01, 20, 40.0],
    'zero' => [0.0, 20, 0.0],
    'grid of one is identity on integers' => [17.0, 1, 17.0],
    'grid of one rounds a fraction' => [17.4, 1, 17.0],
    'large grid' => [499.0, 500, 500.0],
]);

it('rounds half-way values away from zero and never returns negative zero', function (): void {
    expect($this->coords->snap(-30.0, 20))->toBe(-40.0)
        // -5 / 20 rounds to -0; the service must hand back a plain 0.
        ->and(fdiv(1.0, $this->coords->snap(-5.0, 20)))->toBe(INF);
});

it('rejects a grid that is not positive', function (int $grid): void {
    $this->coords->snap(10.0, $grid);
})->with([0, -20])->throws(InvalidArgumentException::class);

it('rejects non-finite input', function (float $bad): void {
    $this->coords->snap($bad, 20);
})->with([NAN, INF, -INF])->throws(InvalidArgumentException::class);

it('clamps into the closed range zero to max', function (float $in, float $max, float $expected): void {
    expect($this->coords->clamp($in, $max))->toBe($expected);
})->with([
    'inside' => [50.0, 100.0, 50.0],
    'negative floors at zero' => [-3.0, 100.0, 0.0],
    'at the lower edge' => [0.0, 100.0, 0.0],
    'at the upper edge' => [100.0, 100.0, 100.0],
    'past the upper edge' => [100.01, 100.0, 100.0],
    'degenerate zero-size canvas' => [5.0, 0.0, 0.0],
]);

it('treats the canvas edges as inside and everything beyond as outside', function (
    float $x,
    float $y,
    bool $inside,
): void {
    expect($this->coords->isWithinBounds($x, $y, 800, 600))->toBe($inside);
})->with([
    'origin' => [0.0, 0.0, true],
    'far corner' => [800.0, 600.0, true],
    'interior' => [400.0, 300.0, true],
    'negative x' => [-0.01, 10.0, false],
    'negative y' => [10.0, -0.01, false],
    'x past width' => [800.01, 10.0, false],
    'y past height' => [10.0, 600.01, false],
    'not a number' => [NAN, 10.0, false],
    'infinite' => [10.0, INF, false],
]);

it('snaps first and clamps second, so the result is always on the canvas', function (): void {
    // 790 snaps up to 800 (on the canvas). 795 on a 30px grid snaps to 810,
    // which is past the 800 edge and must be pulled back to it.
    expect($this->coords->place(790.0, 590.0, 800, 600, 20))->toBe(['x' => 800.0, 'y' => 600.0])
        ->and($this->coords->place(795.0, 10.0, 800, 600, 30))->toBe(['x' => 800.0, 'y' => 0.0]);
});

it('snaps an interior point without moving it off its nearest line', function (): void {
    expect($this->coords->place(123.0, 457.0, 800, 600, 20))->toBe(['x' => 120.0, 'y' => 460.0]);
});

it('pulls a negative point onto the origin instead of storing it', function (): void {
    expect($this->coords->place(-50.0, -1.0, 800, 600, 20))->toBe(['x' => 0.0, 'y' => 0.0]);
});

it('keeps results at the two decimal places the column stores', function (): void {
    expect($this->coords->clamp(10.12345, 100.0))->toBe(10.12)
        ->and($this->coords->clamp(10.999, 100.0))->toBe(11.0);
});

it('folds rotation into zero to under 360', function (float $in, float $expected): void {
    expect($this->coords->normalizeRotation($in))->toBe($expected);
})->with([
    'in range' => [90.0, 90.0],
    'full turn' => [360.0, 0.0],
    'past a turn' => [450.0, 90.0],
    'negative' => [-90.0, 270.0],
    'negative full turn' => [-360.0, 0.0],
    'rounds up onto a full turn' => [359.999, 0.0],
    'fraction kept' => [12.345, 12.35],
]);

<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use InvalidArgumentException;

/**
 * Coordinate arithmetic for the floor plan — pure functions, no database.
 *
 * **The server is authoritative.** The client snaps for preview only; what is
 * stored is what this service returns, so a client that skips or miscomputes
 * the snap cannot place a PC off-grid or off-canvas.
 *
 * Space is layout-local pixels, origin top-left, matching
 * `room_layouts.width/height`. The database already guarantees `pos >= 0`
 * (`floor_plan_positions_coords_check`); the *upper* bound is an application
 * rule and lives here.
 *
 * Results are rounded to two places to match the `decimal(10,2)` columns, so a
 * value read back from storage is exactly the value computed.
 */
final class CoordinateService
{
    /**
     * Round to the nearest grid line. Half-way values round away from zero.
     *
     * Bounds are not applied — see {@see place()}.
     */
    public function snap(float $value, int $gridSize): float
    {
        $this->assertFinite($value);

        if ($gridSize < 1) {
            // `room_layouts_dimensions_check` guarantees grid_size > 0, so this
            // is a caller bug rather than a data state.
            throw new InvalidArgumentException('Grid size must be at least 1.');
        }

        return $this->normalize(round($value / $gridSize) * $gridSize);
    }

    /** Constrain a value to the closed range [0, $max]. */
    public function clamp(float $value, float $max): float
    {
        $this->assertFinite($value);
        $this->assertFinite($max);

        return $this->normalize(min(max($value, 0.0), max($max, 0.0)));
    }

    /**
     * Is the point on the canvas? Edges count as inside.
     *
     * This is the *rejection* test: a request whose coordinates fail it should
     * be answered with a 422 rather than silently moved by {@see place()}.
     */
    public function isWithinBounds(float $x, float $y, int $width, int $height): bool
    {
        return is_finite($x) && is_finite($y)
            && $x >= 0 && $y >= 0
            && $x <= $width && $y <= $height;
    }

    /**
     * Snap a point to the grid, then confine it to the canvas.
     *
     * Order matters: clamping *after* rounding is what guarantees the result is
     * on the canvas — rounding can push a value just inside the edge to one
     * grid line beyond it. The consequence is that a point clamped to the far
     * edge sits on the edge, not necessarily on a grid line, when the canvas
     * size is not a multiple of the grid.
     *
     * @return array{x: float, y: float}
     */
    public function place(float $x, float $y, int $width, int $height, int $gridSize): array
    {
        return [
            'x' => $this->clamp($this->snap($x, $gridSize), $width),
            'y' => $this->clamp($this->snap($y, $gridSize), $height),
        ];
    }

    /** Fold any angle into [0, 360). Storage is `decimal(5,2)`. */
    public function normalizeRotation(float $degrees): float
    {
        $this->assertFinite($degrees);

        $folded = fmod($degrees, 360.0);

        if ($folded < 0) {
            $folded += 360.0;
        }

        // Rounding can land exactly on 360 (e.g. 359.999).
        $rounded = $this->normalize($folded);

        return $rounded >= 360.0 ? 0.0 : $rounded;
    }

    private function normalize(float $value): float
    {
        // `+ 0.0` turns a negative zero into a plain zero.
        return round($value, 2) + 0.0;
    }

    private function assertFinite(float $value): void
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Coordinates must be finite numbers.');
        }
    }
}

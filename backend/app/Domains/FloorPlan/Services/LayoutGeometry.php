<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

/**
 * The Floorplan's geometry rules (Phase 2.11, SDD DD-102) — pure, no database.
 * `frontend/src/features/floor-plan/lib/geometry.ts` mirrors it so the editor
 * can refuse an invalid drop before it ever reaches the server; the server is
 * still the one that decides.
 *
 * Coordinates are layout units with the origin top-left; a position is the
 * **centre** of its shape, so rotation pivots about the middle.
 *
 * The rules, and why they are this strict and no stricter:
 *
 *  - **Everything stays inside the room.** A shape's rotated corners must all
 *    lie within `[0, width] × [0, height]` (edges count as inside).
 *  - **Workstations may not overlap each other.** Two machines on one spot
 *    cannot both be clicked, read or reached by keyboard. Touching is fine —
 *    rows of desks are laid edge to edge. A workstation is a fixed square and
 *    turns only in quarter steps, so its footprint is always this
 *    axis-aligned box.
 *  - **Fixtures may overlap anything.** A PC sits *on* a desk, walls meet at
 *    corners, a zone underlies everything in it. Refusing those would make
 *    legitimate rooms impossible to draw.
 */
final class LayoutGeometry
{
    /** Tolerance for decimal round-tripping (positions are stored at 2 dp). */
    public const EPSILON = 0.01;

    /**
     * A workstation's footprint as [minX, minY, maxX, maxY].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function workstationBox(float $x, float $y, float $size = FloorPlanDefaults::WORKSTATION_SIZE): array
    {
        $half = $size / 2;

        return [$x - $half, $y - $half, $x + $half, $y + $half];
    }

    /**
     * The four corners of a `width × height` rectangle centred on (x, y) and
     * turned `rotation` degrees clockwise (SVG's direction).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function corners(float $x, float $y, float $width, float $height, float $rotation): array
    {
        $rad = deg2rad($rotation);
        $cos = cos($rad);
        $sin = sin($rad);
        $hw = $width / 2;
        $hh = $height / 2;
        $out = [];

        foreach ([[-$hw, -$hh], [$hw, -$hh], [$hw, $hh], [-$hw, $hh]] as [$dx, $dy]) {
            $out[] = [$x + $dx * $cos - $dy * $sin, $y + $dx * $sin + $dy * $cos];
        }

        return $out;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function pointsInsideRoom(array $points, float $roomWidth, float $roomHeight): bool
    {
        foreach ($points as [$px, $py]) {
            if (! is_finite($px) || ! is_finite($py)
                || $px < -self::EPSILON || $py < -self::EPSILON
                || $px > $roomWidth + self::EPSILON || $py > $roomHeight + self::EPSILON) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     */
    public static function boxInsideRoom(array $box, float $roomWidth, float $roomHeight): bool
    {
        return self::pointsInsideRoom([[$box[0], $box[1]], [$box[2], $box[3]]], $roomWidth, $roomHeight);
    }

    /**
     * Strict overlap: boxes that only share an edge do not overlap.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $a
     * @param  array{0: float, 1: float, 2: float, 3: float}  $b
     */
    public static function boxesOverlap(array $a, array $b): bool
    {
        return $a[0] < $b[2] - self::EPSILON
            && $b[0] < $a[2] - self::EPSILON
            && $a[1] < $b[3] - self::EPSILON
            && $b[1] < $a[3] - self::EPSILON;
    }
}

/**
 * Placement arithmetic for the floor-plan editor — pure functions, no React.
 *
 * **Everything here is a preview.** The server snaps, clamps and stores
 * (`CoordinateService` via `PlacePcUnit`), and the map shows what it answered.
 * These functions only make the drag and the keyboard move look like where the
 * unit will land, using the same rules so the preview rarely jumps on commit:
 * snap to the nearest grid line (half-way away from zero), then clamp to the
 * canvas, then round to the storage precision of two decimals.
 */

import type { PlacedPc, PlanLayout } from '../types'

export interface Point {
  x: number
  y: number
}

/** Shift + arrow: one layout pixel, off the grid (WCAG 2.5.7 non-drag fine positioning). */
export const FINE_STEP = 1

export type Direction = 'left' | 'right' | 'up' | 'down'

export const ARROW_DIRECTIONS: Record<string, Direction> = {
  ArrowLeft: 'left',
  ArrowRight: 'right',
  ArrowUp: 'up',
  ArrowDown: 'down',
}

const VECTORS: Record<Direction, [number, number]> = {
  left: [-1, 0],
  right: [1, 0],
  up: [0, -1],
  down: [0, 1],
}

/** Two decimals, like the `decimal(10,2)` columns; never a negative zero. */
export function round2(value: number): number {
  const rounded = Math.round(value * 100) / 100
  return rounded === 0 ? 0 : rounded
}

/** Nearest grid line, half-way away from zero — `CoordinateService::snap`. */
export function snapValue(value: number, grid: number): number {
  if (grid < 1) return round2(value)
  return round2(Math.sign(value) * Math.round(Math.abs(value) / grid) * grid)
}

/** Confine to [0, max] — `CoordinateService::clamp`. */
export function clampValue(value: number, max: number): number {
  return round2(Math.min(Math.max(value, 0), Math.max(max, 0)))
}

/** Snap (optionally) then clamp: where the server would put this point. */
export function previewPoint(point: Point, layout: PlanLayout, snap: boolean): Point {
  const x = snap ? snapValue(point.x, layout.grid_size) : point.x
  const y = snap ? snapValue(point.y, layout.grid_size) : point.y
  return { x: clampValue(x, layout.width), y: clampValue(y, layout.height) }
}

/**
 * One keyboard step. A coarse step moves one grid cell from the nearest grid
 * line — so a unit sitting off the grid lands on it at the first press — and a
 * fine step moves one pixel without snapping.
 */
export function stepPoint(
  point: Point,
  direction: Direction,
  layout: PlanLayout,
  fine: boolean,
): Point {
  const [dx, dy] = VECTORS[direction]

  if (fine) {
    return previewPoint({ x: point.x + dx * FINE_STEP, y: point.y + dy * FINE_STEP }, layout, false)
  }

  const grid = layout.grid_size
  const base = previewPoint(point, layout, true)
  // Already off-grid? The first press only aligns, in the pressed direction.
  const alignedX = snapValue(point.x, grid)
  const alignedY = snapValue(point.y, grid)
  const needsAlign = dx !== 0 ? alignedX !== point.x : alignedY !== point.y

  if (needsAlign) {
    const x =
      dx === 0
        ? base.x
        : dx > 0
          ? Math.ceil(point.x / grid) * grid
          : Math.floor(point.x / grid) * grid
    const y =
      dy === 0
        ? base.y
        : dy > 0
          ? Math.ceil(point.y / grid) * grid
          : Math.floor(point.y / grid) * grid
    return previewPoint({ x, y }, layout, false)
  }

  return previewPoint({ x: base.x + dx * grid, y: base.y + dy * grid }, layout, false)
}

/** Whether a point is on the canvas, edges included — `CoordinateService::isWithinBounds`. */
export function isWithinBounds(point: Point, layout: PlanLayout): boolean {
  return (
    Number.isFinite(point.x) &&
    Number.isFinite(point.y) &&
    point.x >= 0 &&
    point.y >= 0 &&
    point.x <= layout.width &&
    point.y <= layout.height
  )
}

/**
 * How big a unit is drawn, in layout units. Follows the plan's size so a 1900px
 * lab and a 600px office both draw machines at a readable proportion.
 */
export function nodeMetrics(layout: Pick<PlanLayout, 'width' | 'height'>) {
  const unit = Math.max(24, Math.round(Math.max(layout.width, layout.height) / 40))
  return {
    radius: unit * 0.8,
    nameSize: unit * 0.55,
    statusSize: unit * 0.45,
  }
}

/**
 * A grid point with room around it for a newly placed unit: the first, in
 * reading order, at least `clearance` from every placed unit. Null when the
 * plan is full at that spacing — the caller then offers the centre.
 */
export function freeSpot(pcs: PlacedPc[], layout: PlanLayout, clearance: number): Point | null {
  const grid = Math.max(1, layout.grid_size)
  const step = Math.max(grid, Math.ceil(clearance / grid) * grid)
  const start = step

  for (let y = start; y <= layout.height - start / 2; y += step) {
    for (let x = start; x <= layout.width - start / 2; x += step) {
      if (pcs.every((pc) => Math.hypot(pc.x - x, pc.y - y) >= clearance)) {
        return { x, y }
      }
    }
  }

  return null
}

/** The position as the accessible name and the announcements say it. */
export function describePoint(point: Point): string {
  return `x ${formatCoordinate(point.x)}, y ${formatCoordinate(point.y)}`
}

export function formatCoordinate(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0$/, '')
}

/** Identity + status + position: a node's accessible name. */
export function nodeLabel(pc: Pick<PlacedPc, 'name' | 'status'>, point: Point): string {
  return `${pc.name}, ${pc.status.label}, at ${describePoint(point)}`
}

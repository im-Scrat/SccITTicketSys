/**
 * The Floorplan's geometry (Phase 2.11, SDD DD-102) — pure functions, no DOM.
 *
 * Mirrors the server's `LayoutGeometry` so the editor can refuse an invalid
 * drop before it is ever sent; the server still decides. Coordinates are
 * layout units, origin top-left; a position is the **centre** of its shape, so
 * rotation pivots about the middle.
 *
 * The rules: everything stays inside the room; workstations never overlap one
 * another (touching is fine — rows are laid edge to edge); fixtures may overlap
 * anything (a PC sits on a desk, walls meet at corners).
 */

export const EPSILON = 0.01

export interface Point {
  x: number
  y: number
}

/** [minX, minY, maxX, maxY] */
export type Box = [number, number, number, number]

export interface RoomSize {
  width: number
  height: number
}

/** A workstation's square footprint centred on its point. */
export function workstationBox(x: number, y: number, size: number): Box {
  const half = size / 2
  return [x - half, y - half, x + half, y + half]
}

/** The four corners of a `w × h` rectangle centred on (x, y), turned clockwise (SVG's direction). */
export function corners(x: number, y: number, w: number, h: number, rotation: number): Point[] {
  const rad = (rotation * Math.PI) / 180
  const cos = Math.cos(rad)
  const sin = Math.sin(rad)
  return [
    [-w / 2, -h / 2],
    [w / 2, -h / 2],
    [w / 2, h / 2],
    [-w / 2, h / 2],
  ].map(([dx, dy]) => ({ x: x + dx * cos - dy * sin, y: y + dx * sin + dy * cos }))
}

/** The axis-aligned box around a set of points. */
export function boundsOf(points: Point[]): Box {
  const xs = points.map((p) => p.x)
  const ys = points.map((p) => p.y)
  return [Math.min(...xs), Math.min(...ys), Math.max(...xs), Math.max(...ys)]
}

export function pointsInsideRoom(points: Point[], room: RoomSize): boolean {
  return points.every(
    (p) =>
      Number.isFinite(p.x) &&
      Number.isFinite(p.y) &&
      p.x >= -EPSILON &&
      p.y >= -EPSILON &&
      p.x <= room.width + EPSILON &&
      p.y <= room.height + EPSILON,
  )
}

export function boxInsideRoom(box: Box, room: RoomSize): boolean {
  return pointsInsideRoom(
    [
      { x: box[0], y: box[1] },
      { x: box[2], y: box[3] },
    ],
    room,
  )
}

/** Strict overlap: boxes that only share an edge do not overlap. */
export function boxesOverlap(a: Box, b: Box): boolean {
  return (
    a[0] < b[2] - EPSILON && b[0] < a[2] - EPSILON && a[1] < b[3] - EPSILON && b[1] < a[3] - EPSILON
  )
}

/** Round half away from zero, to the nearest multiple of `grid`. */
export function snapValue(value: number, grid: number): number {
  if (grid <= 0 || !Number.isFinite(grid)) return value
  const steps = value / grid
  return (steps < 0 ? -Math.round(-steps) : Math.round(steps)) * grid
}

/** Storage precision: two decimals. */
export function round2(value: number): number {
  return Math.round(value * 100) / 100
}

/** Keep a centre such that `half` either side of it stays within `[0, extent]`. */
function clampCentre(centre: number, half: number, extent: number): number {
  if (extent <= half * 2) return extent / 2
  return Math.min(extent - half, Math.max(half, centre))
}

/**
 * Snap a shape's bounding box so its **top-left corner** lands on a grid
 * intersection — for a workstation (two grid cells wide) that puts the centre
 * and every edge on grid lines — then keep the whole box inside the room.
 */
export function snapAndClamp(
  centre: Point,
  halfExtent: { x: number; y: number },
  room: RoomSize,
  grid: number,
  snap: boolean,
): Point {
  let x = centre.x
  let y = centre.y

  if (snap) {
    x = snapValue(x - halfExtent.x, grid) + halfExtent.x
    y = snapValue(y - halfExtent.y, grid) + halfExtent.y
  }

  return {
    x: round2(clampCentre(x, halfExtent.x, room.width)),
    y: round2(clampCentre(y, halfExtent.y, room.height)),
  }
}

/** Half the width and height of a rotated rectangle's bounding box. */
export function halfExtent(w: number, h: number, rotation: number): { x: number; y: number } {
  const [minX, minY, maxX, maxY] = boundsOf(corners(0, 0, w, h, rotation))
  return { x: (maxX - minX) / 2, y: (maxY - minY) / 2 }
}

/** Fold an angle into [0, 360). */
export function normalizeRotation(degrees: number): number {
  const folded = ((degrees % 360) + 360) % 360
  return round2(folded >= 360 - EPSILON ? 0 : folded)
}

/** The next quarter turn (workstations turn only in quarter steps). */
export function quarterTurn(rotation: number, direction: 1 | -1): number {
  return ((((Math.round(rotation / 90) + direction) % 4) + 4) % 4) * 90
}

export interface ViewBox {
  x: number
  y: number
  width: number
  height: number
}

/**
 * A pointer's client coordinates as layout units, for an SVG drawn with
 * `preserveAspectRatio="xMidYMid meet"`. Computed from the element's bounding
 * rect and its viewBox — the same answer `getScreenCTM()` gives, without an
 * SVG matrix API (absent in jsdom), so it is testable as plain arithmetic.
 */
export function clientToLayout(
  clientX: number,
  clientY: number,
  rect: { left: number; top: number; width: number; height: number },
  viewBox: ViewBox,
): Point {
  if (rect.width <= 0 || rect.height <= 0 || viewBox.width <= 0 || viewBox.height <= 0) {
    return { x: viewBox.x, y: viewBox.y }
  }
  const scale = Math.min(rect.width / viewBox.width, rect.height / viewBox.height)
  const offsetX = (rect.width - viewBox.width * scale) / 2
  const offsetY = (rect.height - viewBox.height * scale) / 2

  return {
    x: viewBox.x + (clientX - rect.left - offsetX) / scale,
    y: viewBox.y + (clientY - rect.top - offsetY) / scale,
  }
}

/** Layout units per CSS pixel at the current view — for thresholds and label sizes. */
export function unitsPerPixel(rect: { width: number; height: number }, viewBox: ViewBox): number {
  if (rect.width <= 0 || rect.height <= 0) return 1
  return 1 / Math.min(rect.width / viewBox.width, rect.height / viewBox.height)
}

/** Whether a workstation at `point` would overlap any of `others` (keyed by id, `self` ignored). */
export function overlapsAny(
  self: string,
  point: Point,
  others: Record<string, Point>,
  size: number,
): string | null {
  const box = workstationBox(point.x, point.y, size)
  for (const [id, other] of Object.entries(others)) {
    if (id === self) continue
    if (boxesOverlap(box, workstationBox(other.x, other.y, size))) return id
  }
  return null
}

/**
 * Lay workstations out in rows, in reading order, starting at the first grid
 * point with room, skipping every spot that would overlap a placed machine.
 * Returns only the ones that fit — the caller says how many did not.
 */
export function arrangeInRows(
  ids: string[],
  occupied: Record<string, Point>,
  room: RoomSize,
  options: { size: number; grid: number; gap: number },
): Record<string, Point> {
  const { size, gap } = options
  const grid = Math.max(1, options.grid)
  const step = Math.max(grid, Math.ceil((size + gap) / grid) * grid)
  const half = size / 2
  const taken: Record<string, Point> = { ...occupied }
  const placed: Record<string, Point> = {}
  let next = 0

  for (let y = snapValue(half + gap, grid); y + half <= room.height + EPSILON; y += step) {
    for (let x = snapValue(half + gap, grid); x + half <= room.width + EPSILON; x += step) {
      if (next >= ids.length) return placed
      const point = { x: round2(x), y: round2(y) }
      if (overlapsAny('', point, taken, size)) continue
      placed[ids[next]] = point
      taken[ids[next]] = point
      next++
    }
  }

  return placed
}

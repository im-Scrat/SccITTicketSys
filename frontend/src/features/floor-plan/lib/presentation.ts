import type { PcStatusValue, Tone } from '../types'

/**
 * How a PC's status is *drawn* — never what it *means*.
 *
 * The meaning (label, and the colour decision as a tone) is the server's, from
 * `PcStatus::label()/tone()`. This file adds only the one channel the server
 * cannot: a **shape** per status. Colour alone must never carry status
 * (FR-FP-004), so every status has its own outline, and the written label sits
 * beside it. Shape + label alone are enough to read the map in greyscale.
 *
 * Fix pass 2: a unit is drawn as a rounded tile with a monitor icon in the
 * status hue (the product's floor-plan visual), and the status *shape* became
 * a small glyph on the tile's corner — still one per status, still beside the
 * written label, so the non-colour channels are unchanged.
 */

export interface StatusShape {
  /** 0 draws a circle; otherwise a regular polygon with this many sides. */
  sides: number
  /** Angle of the first vertex, in degrees (0 = pointing right, -90 = up). */
  startDeg: number
  /** Kept for the glyph renderer; no Phase 2.11 status is drawn dashed. */
  dashed: boolean
}

export const STATUS_SHAPES: Record<PcStatusValue, StatusShape> = {
  available: { sides: 0, startDeg: 0, dashed: false }, // circle
  pending_repair: { sides: 3, startDeg: -90, dashed: false }, // triangle
  needs_fix: { sides: 8, startDeg: 22.5, dashed: false }, // octagon (a stop sign)
}

/** Vertices of a regular polygon centred on the origin, as an SVG `points` value. */
export function polygonPoints(sides: number, radius: number, startDeg: number): string {
  const points: string[] = []
  for (let i = 0; i < sides; i++) {
    const angle = ((startDeg + (360 / sides) * i) * Math.PI) / 180
    points.push(`${round(radius * Math.cos(angle))},${round(radius * Math.sin(angle))}`)
  }
  return points.join(' ')
}

/**
 * SVG fill/stroke utilities per server tone — the same design tokens the
 * shared `PcStatusBadge` uses, so a node and its badge are always the same hue.
 */
export const TONE_SVG: Record<Tone, string> = {
  success: 'fill-success-subtle stroke-success-strong',
  warning: 'fill-warning-subtle stroke-warning-strong',
  danger: 'fill-danger-subtle stroke-danger-strong',
}

/** The icon inside a tile takes `currentColor`, so it is coloured through `text-*`. */
export const TONE_TEXT: Record<Tone, string> = {
  success: 'text-success-strong',
  warning: 'text-warning-strong',
  danger: 'text-danger-strong',
}

/** Statuses that count as an "alert" in the map header, alongside any open ticket. */
export const ALERT_STATUSES: ReadonlySet<PcStatusValue> = new Set(['pending_repair', 'needs_fix'])

function round(value: number): number {
  return Math.round(value * 100) / 100
}

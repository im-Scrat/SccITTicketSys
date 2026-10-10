import type { AssetStatus } from '@/components/ui/StatusPill'
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
  /** Retired machines are drawn with a dashed outline: present, but out of service. */
  dashed: boolean
}

export const STATUS_SHAPES: Record<PcStatusValue, StatusShape> = {
  online: { sides: 0, startDeg: 0, dashed: false }, // circle
  offline: { sides: 4, startDeg: 45, dashed: false }, // square
  under_maintenance: { sides: 3, startDeg: -90, dashed: false }, // triangle
  assigned: { sides: 4, startDeg: 0, dashed: false }, // diamond
  available: { sides: 6, startDeg: 0, dashed: false }, // hexagon
  retired: { sides: 8, startDeg: 22.5, dashed: true }, // dashed octagon
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
 * SVG fill/stroke utilities per server tone. The same design tokens the status
 * pill uses, so a node and its pill are always the same hue. `info` is the
 * primary hue — an *assigned* machine is claimed, not faulty and not spare.
 */
export const TONE_SVG: Record<Tone, string> = {
  neutral: 'fill-surface-sunken stroke-muted',
  success: 'fill-success-subtle stroke-success-strong',
  warning: 'fill-warning-subtle stroke-warning-strong',
  danger: 'fill-danger-subtle stroke-danger-strong',
  info: 'fill-primary-subtle stroke-primary-strong',
}

/** The icon inside a tile takes `currentColor`, so it is coloured through `text-*`. */
export const TONE_TEXT: Record<Tone, string> = {
  neutral: 'text-muted',
  success: 'text-success-strong',
  warning: 'text-warning-strong',
  danger: 'text-danger-strong',
  info: 'text-primary-strong',
}

/**
 * `StatusPill` is keyed by its own five-state vocabulary. The server's tone is
 * what decides the hue here, so a pill is picked *by tone* and its wording is
 * always overridden with the server's label.
 */
export const TONE_PILL: Record<Tone, AssetStatus> = {
  neutral: 'available',
  success: 'online',
  warning: 'maintenance',
  danger: 'offline',
  info: 'assigned',
}

/** Statuses that count as an "alert" in the map header, alongside any open ticket. */
export const ALERT_STATUSES: ReadonlySet<PcStatusValue> = new Set(['offline', 'under_maintenance'])

function round(value: number): number {
  return Math.round(value * 100) / 100
}

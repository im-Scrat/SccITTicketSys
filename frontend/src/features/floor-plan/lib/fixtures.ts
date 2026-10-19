import {
  Archive,
  Columns2,
  DoorOpen,
  LayoutPanelLeft,
  RectangleHorizontal,
  Square,
  SquareDashed,
  Table2,
  type LucideIcon,
} from 'lucide-react'
import type { Fixture, FixtureKind } from '../types'

export interface FixturePresentation {
  label: string
  icon: LucideIcon
  /** Size a new fixture starts at, in layout units. */
  width: number
  height: number
  /** Draw order, lowest first (matches the server's `FloorPlanFixtureKind::layer()`). */
  layer: number
  /** SVG classes — fixtures stay neutral so status colour belongs to workstations alone. */
  shape: string
}

/**
 * How each fixture kind is drawn (Phase 2.11, SDD DD-102). Neutral tones only:
 * the room is the quiet background, and the three status hues are reserved for
 * the machines standing in it.
 */
export const FIXTURES: Record<FixtureKind, FixturePresentation> = {
  zone: {
    label: 'Zone',
    icon: SquareDashed,
    width: 200,
    height: 120,
    layer: 0,
    shape: 'fill-surface-sunken/60 stroke-border [stroke-dasharray:6_4]',
  },
  desk: {
    label: 'Desk',
    icon: Table2,
    width: 80,
    height: 40,
    layer: 1,
    shape: 'fill-surface stroke-muted',
  },
  cabinet: {
    label: 'Cabinet',
    icon: Archive,
    width: 60,
    height: 30,
    layer: 1,
    shape: 'fill-surface-sunken stroke-muted',
  },
  wall: {
    label: 'Wall',
    icon: RectangleHorizontal,
    width: 200,
    height: 8,
    layer: 2,
    shape: 'fill-ink stroke-ink',
  },
  partition: {
    label: 'Partition',
    icon: Columns2,
    width: 120,
    height: 6,
    layer: 2,
    shape: 'fill-muted stroke-muted',
  },
  pillar: {
    label: 'Pillar',
    icon: Square,
    width: 30,
    height: 30,
    layer: 2,
    shape: 'fill-faint stroke-muted',
  },
  door: {
    label: 'Door',
    icon: DoorOpen,
    width: 40,
    height: 8,
    layer: 3,
    shape: 'fill-surface stroke-ink [stroke-dasharray:3_3]',
  },
  window: {
    label: 'Window',
    icon: LayoutPanelLeft,
    width: 80,
    height: 6,
    layer: 3,
    shape: 'fill-info-subtle stroke-info',
  },
}

/** A new fixture of `kind` centred at a point, with a fresh client-generated id. */
export function newFixture(kind: FixtureKind, x: number, y: number): Fixture {
  const preset = FIXTURES[kind]
  return {
    id: crypto.randomUUID(),
    kind,
    x,
    y,
    width: preset.width,
    height: preset.height,
    rotation: 0,
    label: null,
  }
}

/** Fixtures in draw order. */
export function inDrawOrder(fixtures: Fixture[]): Fixture[] {
  return [...fixtures].sort((a, b) => FIXTURES[a.kind].layer - FIXTURES[b.kind].layer)
}

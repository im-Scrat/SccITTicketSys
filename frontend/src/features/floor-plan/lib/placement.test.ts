import { describe, expect, it } from 'vitest'
import { pc } from '../test/fixtures'
import {
  clampValue,
  describePoint,
  freeSpot,
  isWithinBounds,
  nodeLabel,
  previewPoint,
  snapValue,
  stepPoint,
} from './placement'

/**
 * The preview arithmetic. It must match the server's `CoordinateService`
 * (snap half-way away from zero, then clamp, two decimals) closely enough that
 * a drag does not visibly jump when the server's answer arrives — but it is
 * never the authority, which the page tests prove separately.
 */

const layout = { version: 1, width: 1000, height: 600, grid_size: 20 }

describe('snap and clamp — the server rule, mirrored for the preview', () => {
  it('snaps to the nearest grid line, half-way away from zero', () => {
    expect(snapValue(127, 20)).toBe(120)
    expect(snapValue(130, 20)).toBe(140)
    expect(snapValue(129.99, 20)).toBe(120)
    expect(snapValue(0, 20)).toBe(0)
    expect(snapValue(-10, 20)).toBe(-20)
  })

  it('clamps into [0, max] at two decimals, never a negative zero', () => {
    expect(clampValue(-5, 100)).toBe(0)
    expect(Object.is(clampValue(-0, 100), -0)).toBe(false)
    expect(clampValue(150, 100)).toBe(100)
    expect(clampValue(33.456, 100)).toBe(33.46)
  })

  it('snaps then clamps, so a grid line past the edge lands on the edge', () => {
    const odd = { ...layout, width: 1010, height: 610 }
    expect(previewPoint({ x: 1010, y: 610 }, odd, true)).toEqual({ x: 1010, y: 610 })
    expect(previewPoint({ x: 127, y: 83 }, layout, true)).toEqual({ x: 120, y: 80 })
  })

  it('places freely when snapping is off, still on the canvas', () => {
    expect(previewPoint({ x: 127.456, y: 83 }, layout, false)).toEqual({ x: 127.46, y: 83 })
    expect(previewPoint({ x: 2000, y: -4 }, layout, false)).toEqual({ x: 1000, y: 0 })
  })

  it('knows the canvas edges are inside and anything past them is not', () => {
    expect(isWithinBounds({ x: 0, y: 0 }, layout)).toBe(true)
    expect(isWithinBounds({ x: 1000, y: 600 }, layout)).toBe(true)
    expect(isWithinBounds({ x: 1000.01, y: 10 }, layout)).toBe(false)
    expect(isWithinBounds({ x: 10, y: -1 }, layout)).toBe(false)
    expect(isWithinBounds({ x: Number.NaN, y: 10 }, layout)).toBe(false)
  })
})

describe('keyboard steps', () => {
  it('moves one grid cell per arrow', () => {
    expect(stepPoint({ x: 120, y: 80 }, 'right', layout, false)).toEqual({ x: 140, y: 80 })
    expect(stepPoint({ x: 120, y: 80 }, 'left', layout, false)).toEqual({ x: 100, y: 80 })
    expect(stepPoint({ x: 120, y: 80 }, 'up', layout, false)).toEqual({ x: 120, y: 60 })
    expect(stepPoint({ x: 120, y: 80 }, 'down', layout, false)).toEqual({ x: 120, y: 100 })
  })

  it('moves one pixel, off the grid, with Shift', () => {
    expect(stepPoint({ x: 120, y: 80 }, 'right', layout, true)).toEqual({ x: 121, y: 80 })
    expect(stepPoint({ x: 120, y: 80 }, 'up', layout, true)).toEqual({ x: 120, y: 79 })
  })

  it('aligns an off-grid unit to the next grid line in the pressed direction first', () => {
    expect(stepPoint({ x: 125, y: 80 }, 'right', layout, false)).toEqual({ x: 140, y: 80 })
    expect(stepPoint({ x: 125, y: 80 }, 'left', layout, false)).toEqual({ x: 120, y: 80 })
    expect(stepPoint({ x: 120, y: 87 }, 'down', layout, false)).toEqual({ x: 120, y: 100 })
  })

  it('never steps off the canvas', () => {
    expect(stepPoint({ x: 1000, y: 600 }, 'right', layout, false)).toEqual({ x: 1000, y: 600 })
    expect(stepPoint({ x: 0, y: 0 }, 'up', layout, true)).toEqual({ x: 0, y: 0 })
  })
})

describe('free spot for a newly placed unit', () => {
  it('finds the first grid point clear of every placed unit', () => {
    const spot = freeSpot([pc(1, 'online', { x: 60, y: 60 })], layout, 60)
    expect(spot).not.toBeNull()
    expect(spot!.x % 20).toBe(0)
    expect(spot!.y % 20).toBe(0)
    expect(Math.hypot(spot!.x - 60, spot!.y - 60)).toBeGreaterThanOrEqual(60)
  })

  it('returns null when nothing is clear at that spacing', () => {
    const tiny = { ...layout, width: 40, height: 40 }
    expect(freeSpot([pc(1, 'online', { x: 20, y: 20 })], tiny, 60)).toBeNull()
  })
})

describe('what a node says', () => {
  it('names identity, status and position', () => {
    expect(nodeLabel(pc(3, 'online'), { x: 300, y: 120 })).toBe('PC-03, Online, at x 300, y 120')
    expect(describePoint({ x: 127.5, y: 83.25 })).toBe('x 127.5, y 83.25')
  })
})

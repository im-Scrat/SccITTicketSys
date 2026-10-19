import { describe, expect, it } from 'vitest'
import {
  arrangeInRows,
  boxInsideRoom,
  boxesOverlap,
  clientToLayout,
  corners,
  halfExtent,
  normalizeRotation,
  overlapsAny,
  pointsInsideRoom,
  quarterTurn,
  snapAndClamp,
  snapValue,
  workstationBox,
} from './geometry'

const room = { width: 1000, height: 600 }

/** The same cases as the server's LayoutGeometryTest — the two must agree. */
describe('the geometry rules shared with the server', () => {
  it('gives a workstation a square footprint centred on its point', () => {
    expect(workstationBox(100, 60, 40)).toEqual([80, 40, 120, 80])
  })

  it('keeps a workstation touching the walls inside, and one crossing them outside', () => {
    expect(boxInsideRoom(workstationBox(20, 20, 40), room)).toBe(true)
    expect(boxInsideRoom(workstationBox(980, 580, 40), room)).toBe(true)
    expect(boxInsideRoom(workstationBox(19, 100, 40), room)).toBe(false)
    expect(boxInsideRoom(workstationBox(500, 581, 40), room)).toBe(false)
  })

  it('lets boxes touch but not overlap', () => {
    expect(boxesOverlap(workstationBox(100, 100, 40), workstationBox(140, 100, 40))).toBe(false)
    expect(boxesOverlap(workstationBox(100, 100, 40), workstationBox(139, 120, 40))).toBe(true)
  })

  it('turns a rectangle about its centre, clockwise like SVG', () => {
    const turned = corners(100, 100, 40, 20, 90).map((p) => [Math.round(p.x), Math.round(p.y)])
    expect(turned).toEqual(
      expect.arrayContaining([
        [110, 80],
        [110, 120],
        [90, 120],
        [90, 80],
      ]),
    )
  })

  it('finds a rotated fixture corner that leaves the room', () => {
    expect(pointsInsideRoom(corners(100, 20, 200, 8, 45), room)).toBe(false)
  })

  it('refuses points that are not finite numbers', () => {
    expect(pointsInsideRoom([{ x: Number.NaN, y: 1 }], room)).toBe(false)
  })
})

describe('snapping', () => {
  it('rounds half away from zero, like the server always has', () => {
    expect(snapValue(30, 20)).toBe(40)
    expect(snapValue(29.9, 20)).toBe(20)
    expect(snapValue(-30, 20)).toBe(-40)
  })

  it('snaps a box by its top-left corner, so a workstation centre lands on an intersection', () => {
    expect(snapAndClamp({ x: 107, y: 93 }, { x: 20, y: 20 }, room, 20, true)).toEqual({
      x: 100,
      y: 100,
    })
  })

  it('places freely when snapping is off, at storage precision', () => {
    expect(snapAndClamp({ x: 107.456, y: 93.333 }, { x: 20, y: 20 }, room, 20, false)).toEqual({
      x: 107.46,
      y: 93.33,
    })
  })

  it('keeps the whole shape inside the room', () => {
    expect(snapAndClamp({ x: 2000, y: -50 }, { x: 20, y: 20 }, room, 20, true)).toEqual({
      x: 980,
      y: 20,
    })
  })

  it('measures a rotated rectangle by its bounding box', () => {
    expect(halfExtent(80, 40, 90)).toEqual({ x: expect.closeTo(20), y: expect.closeTo(40) })
  })
})

describe('rotation', () => {
  it('folds any angle into [0, 360)', () => {
    expect(normalizeRotation(360)).toBe(0)
    expect(normalizeRotation(-15)).toBe(345)
    expect(normalizeRotation(725)).toBe(5)
  })

  it('turns a workstation in quarter steps, both ways', () => {
    expect(quarterTurn(0, 1)).toBe(90)
    expect(quarterTurn(270, 1)).toBe(0)
    expect(quarterTurn(0, -1)).toBe(270)
  })
})

describe('pointer conversion (xMidYMid meet)', () => {
  it('maps the viewBox one to one when the aspect ratios match', () => {
    const rect = { left: 0, top: 0, width: 1000, height: 600 }
    expect(clientToLayout(250, 300, rect, { x: 0, y: 0, width: 1000, height: 600 })).toEqual({
      x: 250,
      y: 300,
    })
  })

  it('accounts for zoom and pan', () => {
    const rect = { left: 0, top: 0, width: 1000, height: 600 }
    // Zoomed 2× into the bottom-right quarter.
    expect(clientToLayout(500, 300, rect, { x: 500, y: 300, width: 500, height: 300 })).toEqual({
      x: 750,
      y: 450,
    })
  })

  it('accounts for letterboxing when the element is wider than the room', () => {
    const rect = { left: 10, top: 0, width: 1200, height: 600 }
    // Drawn 1000 wide, centred: 100 px of empty space on each side.
    expect(clientToLayout(110, 0, rect, { x: 0, y: 0, width: 1000, height: 600 })).toEqual({
      x: 0,
      y: 0,
    })
  })
})

describe('overlap and arrangement', () => {
  it('names the workstation a point would overlap, ignoring itself', () => {
    const others = { a: { x: 100, y: 100 }, b: { x: 300, y: 100 } }
    expect(overlapsAny('a', { x: 110, y: 100 }, others, 40)).toBeNull()
    expect(overlapsAny('c', { x: 110, y: 100 }, others, 40)).toBe('a')
  })

  it('lays machines out in rows, skipping occupied spots', () => {
    const placed = arrangeInRows(['x', 'y'], { taken: { x: 40, y: 40 } }, room, {
      size: 40,
      grid: 20,
      gap: 0,
    })
    // 20 and 60 both overlap the taken box at 40; 100 is the first clear spot.
    expect(placed.x).toEqual({ x: 100, y: 20 })
    expect(
      Object.values(placed).every((p) => !overlapsAny('', p, { taken: { x: 40, y: 40 } }, 40)),
    ).toBe(true)
  })

  it('returns only the machines that fit', () => {
    const tiny = { width: 80, height: 40 }
    expect(
      Object.keys(arrangeInRows(['a', 'b', 'c'], {}, tiny, { size: 40, grid: 20, gap: 0 })),
    ).toEqual(['a', 'b'])
  })
})

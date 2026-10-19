import { describe, expect, it } from 'vitest'
import { fixture, roomPlan } from '../test/fixtures'
import {
  changedKeys,
  countChanges,
  docFromPlan,
  merge3,
  pruneWorkstations,
  resolveConflicts,
  toSaveRequest,
  type LayoutDoc,
} from './layoutDocument'

const base = (): LayoutDoc => docFromPlan(roomPlan({ fixtures: [fixture()] }))!

const move = (doc: LayoutDoc, id: string, x: number): LayoutDoc => ({
  ...doc,
  workstations: { ...doc.workstations, [id]: { ...doc.workstations[id], x } },
})

describe('the draft document', () => {
  it('holds geometry only — machines, fixtures and the layout settings', () => {
    const doc = base()
    expect(doc.settings).toEqual({ width: 1000, height: 600, grid_size: 20 })
    expect(doc.workstations['pc-uuid-1']).toEqual({ x: 100, y: 120, rotation: 0 })
    expect(Object.keys(doc.fixtures)).toEqual(['fixture-uuid-1'])
  })

  it('is null for a room with no layout', () => {
    expect(docFromPlan(roomPlan({ layout: null }))).toBeNull()
  })

  it('becomes the save request, with the revision it was based on', () => {
    const request = toSaveRequest(base(), 3)
    expect(request.expected_revision).toBe(3)
    expect(request.workstations[0]).toEqual({ pc_unit: 'pc-uuid-1', x: 100, y: 120, rotation: 0 })
    expect(request.fixtures[0]).toMatchObject({ id: 'fixture-uuid-1', kind: 'desk' })
  })

  it('sends a blank label as null', () => {
    const doc = base()
    doc.fixtures['fixture-uuid-1'] = { ...doc.fixtures['fixture-uuid-1'], label: '   ' }
    expect(toSaveRequest(doc, 1).fixtures[0].label).toBeNull()
  })

  it('counts each changed object once', () => {
    const doc = base()
    const changed = move(move(doc, 'pc-uuid-1', 200), 'pc-uuid-1', 220)
    expect(countChanges(doc, changed)).toBe(1)
    expect(changedKeys(doc, { ...changed, settings: { ...doc.settings, grid_size: 10 } })).toEqual(
      expect.arrayContaining(['pc:pc-uuid-1', 'settings']),
    )
  })

  it('drops machines that have left the room', () => {
    const { doc, dropped } = pruneWorkstations(base(), new Set(['pc-uuid-1']))
    expect(Object.keys(doc.workstations)).toEqual(['pc-uuid-1'])
    expect(dropped).toEqual(['pc-uuid-2', 'pc-uuid-3'])
  })
})

describe('merge3 — combining two administrators’ saves object by object', () => {
  it('carries my change over when they did not touch that object', () => {
    const b = base()
    const mine = move(b, 'pc-uuid-1', 200)
    const theirs = move(b, 'pc-uuid-2', 500)
    const result = merge3(b, mine, theirs)

    expect(result.conflicts).toEqual([])
    expect(result.applied).toBe(1)
    expect(result.doc.workstations['pc-uuid-1'].x).toBe(200)
    expect(result.doc.workstations['pc-uuid-2'].x).toBe(500)
  })

  it('keeps their change when I did not touch that object', () => {
    const b = base()
    const result = merge3(b, b, move(b, 'pc-uuid-1', 700))
    expect(result.applied).toBe(0)
    expect(result.doc.workstations['pc-uuid-1'].x).toBe(700)
  })

  it('does not count an identical change on both sides as a conflict', () => {
    const b = base()
    expect(merge3(b, move(b, 'pc-uuid-1', 300), move(b, 'pc-uuid-1', 300)).conflicts).toEqual([])
  })

  it('lists a clash for a decision, provisionally keeping theirs', () => {
    const b = base()
    const result = merge3(b, move(b, 'pc-uuid-1', 200), move(b, 'pc-uuid-1', 800))

    expect(result.conflicts).toHaveLength(1)
    expect(result.conflicts[0].key).toBe('pc:pc-uuid-1')
    expect(result.doc.workstations['pc-uuid-1'].x).toBe(800)
    expect(resolveConflicts(result, { 'pc:pc-uuid-1': 'mine' }).workstations['pc-uuid-1'].x).toBe(
      200,
    )
  })

  it('treats "I removed it, they moved it" as a clash, not a silent loss', () => {
    const b = base()
    const mine = { ...b, workstations: { ...b.workstations } }
    delete mine.workstations['pc-uuid-1']
    const result = merge3(b, mine, move(b, 'pc-uuid-1', 600))

    expect(result.conflicts.map((c) => c.key)).toEqual(['pc:pc-uuid-1'])
    expect(result.conflicts[0].mine).toBeUndefined()
  })

  it('carries over a fixture I added and one they added', () => {
    const b = base()
    const mine = { ...b, fixtures: { ...b.fixtures, mine: fixture({ id: 'mine', kind: 'wall' }) } }
    const theirs = {
      ...b,
      fixtures: { ...b.fixtures, theirs: fixture({ id: 'theirs', kind: 'door' }) },
    }
    const result = merge3(b, mine, theirs)

    expect(Object.keys(result.doc.fixtures).sort()).toEqual(['fixture-uuid-1', 'mine', 'theirs'])
  })

  it('merges the layout settings as one object', () => {
    const b = base()
    const result = merge3(b, { ...b, settings: { ...b.settings, width: 1200 } }, b)
    expect(result.doc.settings.width).toBe(1200)
  })
})

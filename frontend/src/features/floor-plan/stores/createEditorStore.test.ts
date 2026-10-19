import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { docFromPlan, type LayoutDoc } from '../lib/layoutDocument'
import { roomPlan } from '../test/fixtures'
import { HISTORY_LIMIT, createEditorStore, isDirty } from './createEditorStore'

const shift =
  (id: string, dx: number) =>
  (doc: LayoutDoc): LayoutDoc => ({
    ...doc,
    workstations: {
      ...doc.workstations,
      [id]: { ...doc.workstations[id], x: doc.workstations[id].x + dx },
    },
  })

let store: ReturnType<typeof createEditorStore>

beforeEach(() => {
  vi.useFakeTimers()
  store = createEditorStore({ snap: true })
  store.getState().reset(docFromPlan(roomPlan()), 3)
})

afterEach(() => vi.useRealTimers())

describe('the editor store', () => {
  it('starts clean, at the server’s revision', () => {
    expect(isDirty(store.getState())).toBe(false)
    expect(store.getState().revision).toBe(3)
  })

  it('records each edit as one undo step and is dirty until undone', () => {
    store.getState().edit(shift('pc-uuid-1', 20))
    expect(isDirty(store.getState())).toBe(true)

    store.getState().undo()
    expect(isDirty(store.getState())).toBe(false)

    store.getState().redo()
    expect(store.getState().draft!.workstations['pc-uuid-1'].x).toBe(120)
  })

  it('folds a quick burst of arrow-key moves into one undo step', () => {
    store.getState().edit(shift('pc-uuid-1', 20), 'nudge')
    vi.advanceTimersByTime(100)
    store.getState().edit(shift('pc-uuid-1', 20), 'nudge')
    expect(store.getState().past).toHaveLength(1)

    vi.advanceTimersByTime(2000)
    store.getState().edit(shift('pc-uuid-1', 20), 'nudge')
    expect(store.getState().past).toHaveLength(2)
  })

  it('clears redo once a new edit is made', () => {
    store.getState().edit(shift('pc-uuid-1', 20))
    store.getState().undo()
    store.getState().edit(shift('pc-uuid-2', 20))
    expect(store.getState().future).toHaveLength(0)
  })

  it('keeps a bounded history', () => {
    for (let i = 0; i < HISTORY_LIMIT + 20; i++) store.getState().edit(shift('pc-uuid-1', 1))
    expect(store.getState().past).toHaveLength(HISTORY_LIMIT)
  })

  it('drops a selection whose object no longer exists', () => {
    store.getState().select(['pc:pc-uuid-1', 'pc:pc-uuid-2'])
    store.getState().edit((doc) => {
      const workstations = { ...doc.workstations }
      delete workstations['pc-uuid-1']
      return { ...doc, workstations }
    })
    expect(store.getState().selection).toEqual(['pc:pc-uuid-2'])
  })

  it('toggles objects in and out of the selection with additive select', () => {
    store.getState().select(['pc:pc-uuid-1'])
    store.getState().select(['pc:pc-uuid-2'], true)
    store.getState().select(['pc:pc-uuid-1'], true)
    expect(store.getState().selection).toEqual(['pc:pc-uuid-2'])
  })

  it('rebases a draft onto a newer server copy without losing undo', () => {
    store.getState().edit(shift('pc-uuid-1', 20))
    const newer = docFromPlan(roomPlan())!
    store.getState().rebase(newer, 4, store.getState().draft!)

    expect(store.getState().revision).toBe(4)
    expect(store.getState().past.length).toBeGreaterThan(0)
    expect(isDirty(store.getState())).toBe(true)
  })

  it('clears server refusals as soon as the draft changes', () => {
    store.getState().setInvalid({ 'pc:pc-uuid-1': 'Overlaps another workstation.' })
    store.getState().edit(shift('pc-uuid-1', 20))
    expect(store.getState().invalid).toEqual({})
  })
})

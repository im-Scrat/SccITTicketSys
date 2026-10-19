import { afterEach, describe, expect, it, vi } from 'vitest'
import { roomPlan } from '../test/fixtures'
import {
  clearDraft,
  clearFloorplanDrafts,
  clearFloorplanDraftsExcept,
  readDraft,
  writeDraft,
  type StoredDraft,
} from './draftStorage'
import { docFromPlan } from './layoutDocument'

const doc = docFromPlan(roomPlan())!
const stored = (): StoredDraft => ({
  layoutVersion: 1,
  revision: 3,
  base: doc,
  draft: doc,
  savedAt: Date.now(),
})

afterEach(() => {
  window.sessionStorage.clear()
  vi.restoreAllMocks()
})

describe('the Floorplan draft kept in this tab', () => {
  it('lives in sessionStorage, keyed by user and room', () => {
    writeDraft('user-a', 'room-1', stored())

    expect(readDraft('user-a', 'room-1')?.revision).toBe(3)
    expect(readDraft('user-b', 'room-1')).toBeNull()
    expect(readDraft('user-a', 'room-2')).toBeNull()
    expect(window.localStorage.length).toBe(0)
  })

  it('is cleared on save or discard', () => {
    writeDraft('user-a', 'room-1', stored())
    clearDraft('user-a', 'room-1')
    expect(readDraft('user-a', 'room-1')).toBeNull()
  })

  it('is not offered back when it is half a day old', () => {
    writeDraft('user-a', 'room-1', { ...stored(), savedAt: Date.now() - 13 * 60 * 60 * 1000 })
    expect(readDraft('user-a', 'room-1')).toBeNull()
  })

  it('goes for everyone at sign-out, and for everyone else at sign-in', () => {
    writeDraft('user-a', 'room-1', stored())
    writeDraft('user-b', 'room-1', stored())

    clearFloorplanDraftsExcept('user-a')
    expect(readDraft('user-a', 'room-1')).not.toBeNull()
    expect(readDraft('user-b', 'room-1')).toBeNull()

    clearFloorplanDrafts()
    expect(readDraft('user-a', 'room-1')).toBeNull()
  })

  it('never breaks the editor when storage throws', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    expect(() => writeDraft('user-a', 'room-1', stored())).not.toThrow()
  })
})

import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  clearReportDrafts,
  clearReportDraftsExcept,
  readReportDraft,
  REPORT_DRAFT_TTL_MS,
  type ReportDraftValues,
  saveReportDraft,
  useReportDraft,
} from './useReportDraft'

const DRAFT: ReportDraftValues = {
  title: 'Projector will not start',
  description: 'It powers on, then shuts down.',
  category: 'other',
  category_detail: 'Smartboard pen',
  priority: 'high',
  building: 'b-1',
  room: 'room-lab',
  item: 'pc:11111111-1111-4111-8111-111111111111',
}

beforeEach(() => {
  sessionStorage.clear()
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('the stored draft', () => {
  it('round-trips the text fields and the choices, per user', () => {
    saveReportDraft('user-1', DRAFT)

    expect(readReportDraft('user-1')).toEqual(DRAFT)
    expect(readReportDraft('user-2')).toBeNull()
  })

  it('lives in this tab’s session storage, never local storage', () => {
    saveReportDraft('user-1', DRAFT)

    expect(sessionStorage.getItem('sccit.reportDraft.user-1')).not.toBeNull()
    expect(localStorage.getItem('sccit.reportDraft.user-1')).toBeNull()
  })

  it('goes stale after twelve hours', () => {
    const savedAt = Date.parse('2026-10-16T07:00:00Z')
    saveReportDraft('user-1', DRAFT, savedAt)

    expect(readReportDraft('user-1', savedAt + REPORT_DRAFT_TTL_MS - 1)).toEqual(DRAFT)
    expect(readReportDraft('user-1', savedAt + REPORT_DRAFT_TTL_MS + 1)).toBeNull()
    // …and the stale entry is gone, not just ignored.
    expect(sessionStorage.getItem('sccit.reportDraft.user-1')).toBeNull()
  })

  it('stores nothing for an empty form', () => {
    saveReportDraft('user-1', {
      ...DRAFT,
      ...Object.fromEntries(Object.keys(DRAFT).map((k) => [k, ''])),
    })

    expect(sessionStorage.getItem('sccit.reportDraft.user-1')).toBeNull()
  })

  it('stores nothing for a building alone — the form chooses that by itself', () => {
    const untouched = {
      ...DRAFT,
      ...Object.fromEntries(Object.keys(DRAFT).map((k) => [k, ''])),
      building: 'b-1',
    }

    saveReportDraft('user-1', untouched)
    expect(sessionStorage.getItem('sccit.reportDraft.user-1')).toBeNull()

    // …and one stored before this rule is not offered back either.
    sessionStorage.setItem(
      'sccit.reportDraft.user-1',
      JSON.stringify({ v: 1, savedAt: Date.now(), values: untouched }),
    )
    expect(readReportDraft('user-1')).toBeNull()
  })

  it('ignores an entry this form did not write', () => {
    sessionStorage.setItem('sccit.reportDraft.user-1', '{"title":"not a draft"}')
    expect(readReportDraft('user-1')).toBeNull()

    sessionStorage.setItem('sccit.reportDraft.user-1', 'not json')
    expect(readReportDraft('user-1')).toBeNull()
  })

  it('keeps working when storage throws (private browsing, a full quota)', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError')
    })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('SecurityError')
    })

    expect(() => saveReportDraft('user-1', DRAFT)).not.toThrow()
    expect(readReportDraft('user-1')).toBeNull()
  })

  it('is removed for every user on sign-out', () => {
    saveReportDraft('user-1', DRAFT)
    saveReportDraft('user-2', DRAFT)
    sessionStorage.setItem('sccit.pendingScan', 'QR-1')

    clearReportDrafts()

    expect(readReportDraft('user-1')).toBeNull()
    expect(readReportDraft('user-2')).toBeNull()
    // Only drafts — not what other features keep in the same storage.
    expect(sessionStorage.getItem('sccit.pendingScan')).toBe('QR-1')
  })

  it('is removed for everyone but the person signing in', () => {
    saveReportDraft('user-1', DRAFT)
    saveReportDraft('user-2', DRAFT)
    sessionStorage.setItem('sccit.pendingScan', 'QR-1')

    clearReportDraftsExcept('user-2')

    expect(readReportDraft('user-1')).toBeNull()
    expect(readReportDraft('user-2')).toEqual(DRAFT)
    expect(sessionStorage.getItem('sccit.pendingScan')).toBe('QR-1')
  })
})

describe('useReportDraft', () => {
  it('offers back a saved draft once, with the restore note showing', () => {
    saveReportDraft('user-1', DRAFT)

    const { result } = renderHook(() => useReportDraft('user-1'))

    expect(result.current.restored).toEqual(DRAFT)
    expect(result.current.noteVisible).toBe(true)

    act(() => result.current.dismissNote())
    expect(result.current.noteVisible).toBe(false)
  })

  it('shows no note when there was nothing to restore', () => {
    const { result } = renderHook(() => useReportDraft('user-1'))

    expect(result.current.restored).toBeNull()
    expect(result.current.noteVisible).toBe(false)
  })

  it('saves as the reporter types, a moment after they stop', () => {
    vi.useFakeTimers()
    const { result } = renderHook(() => useReportDraft('user-1'))

    act(() => result.current.save({ ...DRAFT, title: 'Proj' }))
    act(() => result.current.save(DRAFT))
    expect(readReportDraft('user-1')).toBeNull()

    act(() => vi.advanceTimersByTime(500))
    expect(readReportDraft('user-1')).toEqual(DRAFT)
  })

  it('saves at once when the tab is hidden — the camera app has come to the front', () => {
    vi.useFakeTimers()
    const { result } = renderHook(() => useReportDraft('user-1'))
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')

    act(() => result.current.save(DRAFT))
    act(() => void document.dispatchEvent(new Event('visibilitychange')))

    expect(readReportDraft('user-1')).toEqual(DRAFT)
    visibility.mockRestore()
  })

  it('clears the draft once the report is filed, and ignores later saves', () => {
    vi.useFakeTimers()
    saveReportDraft('user-1', DRAFT)
    const { result } = renderHook(() => useReportDraft('user-1'))

    act(() => result.current.clear())
    act(() => result.current.save(DRAFT))
    act(() => vi.advanceTimersByTime(1000))

    expect(readReportDraft('user-1')).toBeNull()
    expect(result.current.noteVisible).toBe(false)
  })

  it('does nothing without a signed-in user', () => {
    vi.useFakeTimers()
    const { result } = renderHook(() => useReportDraft(null))

    act(() => result.current.save(DRAFT))
    act(() => vi.advanceTimersByTime(1000))

    expect(sessionStorage.length).toBe(0)
  })
})

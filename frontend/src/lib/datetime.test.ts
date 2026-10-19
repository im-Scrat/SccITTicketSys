import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { formatRelative } from './datetime'

const NOW = new Date('2026-10-17T10:00:00Z')

/** An ISO time `minutes` away from the frozen "now" (negative = in the past). */
const at = (minutes: number) => new Date(NOW.getTime() + minutes * 60_000).toISOString()

describe('formatRelative', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('reads the past as "… ago"', () => {
    expect(formatRelative(at(-5))).toBe('5m ago')
    expect(formatRelative(at(-3 * 60))).toBe('3h ago')
    expect(formatRelative(at(-2 * 24 * 60))).toBe('2d ago')
  })

  it('reads the future as "in …" — a due time is not "just now"', () => {
    expect(formatRelative(at(5))).toBe('in 5m')
    expect(formatRelative(at(3 * 60))).toBe('in 3h')
    expect(formatRelative(at(2 * 24 * 60))).toBe('in 2d')
  })

  it('calls anything inside a minute either way "just now"', () => {
    expect(formatRelative(at(0))).toBe('just now')
    expect(formatRelative(at(0.4))).toBe('just now')
    expect(formatRelative(at(-0.4))).toBe('just now')
  })

  it('falls back to a date past a week, in either direction', () => {
    expect(formatRelative(at(-10 * 24 * 60))).not.toMatch(/ago|in /)
    expect(formatRelative(at(10 * 24 * 60))).not.toMatch(/ago|^in /)
  })

  it('shows a dash for nothing', () => {
    expect(formatRelative(null)).toBe('—')
    expect(formatRelative(undefined)).toBe('—')
  })
})

import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDebouncedValue } from './useDebouncedValue'

// The one place fake timers are used: a hook with no DOM, so nothing here
// waits on Testing Library's polling (which does not see Vitest's clock).
beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useDebouncedValue', () => {
  it('starts with the value it was given', () => {
    const { result } = renderHook(() => useDebouncedValue('lab-a', 250))
    expect(result.current).toBe('lab-a')
  })

  it('follows a new value only once it has stopped changing for the delay', () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value, 250), {
      initialProps: { value: 'lab-a' },
    })

    rerender({ value: 'lab-b' })
    act(() => vi.advanceTimersByTime(249))
    expect(result.current).toBe('lab-a')

    act(() => vi.advanceTimersByTime(1))
    expect(result.current).toBe('lab-b')
  })

  it('skips the values passed through on the way — arrowing through a list settles once', () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value, 250), {
      initialProps: { value: 'room-1' },
    })

    for (const room of ['room-2', 'room-3', 'room-4']) {
      rerender({ value: room })
      act(() => vi.advanceTimersByTime(100))
    }
    expect(result.current).toBe('room-1')

    act(() => vi.advanceTimersByTime(250))
    expect(result.current).toBe('room-4')
  })

  it('defaults to 300 ms', () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value), {
      initialProps: { value: 'a' },
    })

    rerender({ value: 'b' })
    act(() => vi.advanceTimersByTime(299))
    expect(result.current).toBe('a')
    act(() => vi.advanceTimersByTime(1))
    expect(result.current).toBe('b')
  })
})

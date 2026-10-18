import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { OverallParams, OverallSectionKey, ReportParams } from '../types'

/** URL keys a report reads — the same names the API takes (1:1). */
export const REPORT_PARAM_KEYS = [
  'period',
  'anchor',
  'from',
  'to',
  'since_year',
  'granularity',
  'compare',
  'room_type',
  'building',
  'room',
  'status',
  'category',
  'problem_area',
] as const satisfies ReadonlyArray<keyof ReportParams>

/** Display options, not filters: they never count as "a filter applied". */
const DISPLAY_KEYS = new Set<string>(['granularity'])

/**
 * Report filter state, **kept in the URL** — the rule every filtered list here
 * follows. A filtered report survives a refresh, the back button, a bookmark
 * and being pasted to a colleague; React state would lose all four.
 *
 * Absent keys mean the server's defaults (this month, compared with the
 * previous one, every location and status), so a bare URL is a valid report.
 */
export function useReportFilters() {
  const [searchParams, setSearchParams] = useSearchParams()

  const params = useMemo<ReportParams>(() => {
    const out: ReportParams = {}
    for (const key of REPORT_PARAM_KEYS) {
      const value = searchParams.get(key)
      if (value !== null && value !== '') out[key] = value
    }
    return out
  }, [searchParams])

  const patch = useCallback(
    (changes: Partial<Record<keyof ReportParams, string | null>>) =>
      setSearchParams(
        (previous) => {
          const next = new URLSearchParams(previous)
          for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === undefined || value === '') next.delete(key)
            else next.set(key, value)
          }
          return next
        },
        { replace: true },
      ),
    [setSearchParams],
  )

  const reset = useCallback(
    () =>
      setSearchParams(
        (previous) => {
          const next = new URLSearchParams(previous)
          for (const key of REPORT_PARAM_KEYS) next.delete(key)
          return next
        },
        { replace: true },
      ),
    [setSearchParams],
  )

  const activeCount = Object.keys(params).filter((key) => !DISPLAY_KEYS.has(key)).length

  return { params, patch, reset, activeCount }
}

/** Overall Reports' own URL keys (Phase 2.10 R4), on top of the shared ones. */
export const OVERALL_PARAM_KEYS = [
  ...REPORT_PARAM_KEYS,
  'equipment',
  'maintenance_class',
  'component_type',
  'location_group',
] as const satisfies ReadonlyArray<keyof OverallParams>

/** Display choices, never "a filter applied": the grouping and the Locations grouping. */
const OVERALL_DISPLAY_KEYS = new Set<string>(['granularity', 'location_group'])

/**
 * The Overall Reports filters, in the URL like every report's (see
 * {@link useReportFilters}), plus the section being read (`?section=`), so a
 * bookmarked report reopens on the same section with the same filters.
 */
export function useOverallFilters() {
  const [searchParams, setSearchParams] = useSearchParams()

  const params = useMemo<OverallParams>(() => {
    const out: OverallParams = {}
    for (const key of OVERALL_PARAM_KEYS) {
      const value = searchParams.get(key)
      if (value !== null && value !== '') out[key] = value
    }
    return out
  }, [searchParams])

  const section = (searchParams.get('section') ?? 'overview') as OverallSectionKey

  const patch = useCallback(
    (changes: Partial<Record<keyof OverallParams | 'section', string | null>>) =>
      setSearchParams(
        (previous) => {
          const next = new URLSearchParams(previous)
          for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === undefined || value === '') next.delete(key)
            else next.set(key, value)
          }
          return next
        },
        { replace: true },
      ),
    [setSearchParams],
  )

  const reset = useCallback(
    () =>
      setSearchParams(
        (previous) => {
          const next = new URLSearchParams(previous)
          for (const key of OVERALL_PARAM_KEYS) next.delete(key)
          return next
        },
        { replace: true },
      ),
    [setSearchParams],
  )

  const activeCount = Object.keys(params).filter((key) => !OVERALL_DISPLAY_KEYS.has(key)).length

  return { params, section, patch, reset, activeCount }
}

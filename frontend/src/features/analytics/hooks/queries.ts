import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchOverallRecords,
  fetchOverallSection,
  fetchReportOptions,
  fetchTicketOverview,
  searchEquipment,
} from '../api/reportsApi'
import type { OverallParams, OverallSectionKey, ReportParams } from '../types'

/**
 * Under `['dashboard', …]` on purpose: the administrators' live channel
 * invalidates that whole prefix on every change, so a report refreshes when a
 * ticket arrives with no hook of its own.
 */
export const reportKeys = {
  ticketOverview: (params: ReportParams) => ['dashboard', 'ticket-overview', params] as const,
  options: () => ['dashboard', 'report-options'] as const,
  overall: (section: OverallSectionKey, params: OverallParams) =>
    ['overall-report', section, params] as const,
  records: (table: string, params: OverallParams, page: object) =>
    ['overall-report-records', table, params, page] as const,
}

/**
 * The ticket overview. `staleTime` mirrors the server's 30-second cache, and
 * the previous frame stays on screen while a new filter loads, dimmed, rather
 * than flashing skeletons.
 */
export function useTicketOverview(params: ReportParams, enabled = true) {
  return useQuery({
    queryKey: reportKeys.ticketOverview(params),
    queryFn: () => fetchTicketOverview(params),
    staleTime: 30_000,
    placeholderData: keepPreviousData,
    enabled,
    retry: (failures, error) => {
      const status = (error as { response?: { status?: number } }).response?.status
      // A 403 or 422 will not change on retry.
      return status !== 403 && status !== 422 && failures < 2
    },
  })
}

export function useReportOptions(enabled = true) {
  return useQuery({
    queryKey: reportKeys.options(),
    queryFn: fetchReportOptions,
    staleTime: 60_000,
    enabled,
  })
}

/**
 * One Overall Reports section. Not refetched on the live channel's
 * invalidations while being read — the page shows "New activity since this
 * report loaded" instead (see OverallReportsPage); `staleTime` mirrors the
 * server's 60-second cache.
 */
export function useOverallSection(
  section: OverallSectionKey,
  params: OverallParams,
  enabled = true,
) {
  return useQuery({
    queryKey: reportKeys.overall(section, params),
    queryFn: () => fetchOverallSection(section, params),
    staleTime: 60_000,
    placeholderData: keepPreviousData,
    enabled,
    retry: (failures, error) => {
      const status = (error as { response?: { status?: number } }).response?.status
      return status !== 403 && status !== 422 && failures < 2
    },
  })
}

export function useOverallRecords(
  table: string,
  params: OverallParams,
  page: { page: number; per_page?: number; sort?: string; direction?: 'asc' | 'desc' },
  enabled = true,
) {
  return useQuery({
    queryKey: reportKeys.records(table, params, page),
    queryFn: () => fetchOverallRecords(table, params, page),
    staleTime: 60_000,
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useEquipmentSearch(q: string, enabled = true) {
  return useQuery({
    queryKey: ['report-equipment-options', q],
    queryFn: () => searchEquipment(q),
    staleTime: 30_000,
    enabled,
  })
}

import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { fetchReportOptions, fetchTicketOverview } from '../api/reportsApi'
import type { ReportParams } from '../types'

/**
 * Under `['dashboard', …]` on purpose: the administrators' live channel
 * invalidates that whole prefix on every change, so a report refreshes when a
 * ticket arrives with no hook of its own.
 */
export const reportKeys = {
  ticketOverview: (params: ReportParams) => ['dashboard', 'ticket-overview', params] as const,
  options: () => ['dashboard', 'report-options'] as const,
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

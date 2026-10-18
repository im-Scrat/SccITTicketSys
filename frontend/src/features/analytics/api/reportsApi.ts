import { api } from '@/services/api'
import type { ReportOptions, ReportParams, TicketOverview } from '../types'

/**
 * Report endpoints (Phase 2.10) — Administrator-only on the server, which
 * refuses anyone else whatever this client sends (ReportsAccess).
 */
export async function fetchTicketOverview(params: ReportParams): Promise<TicketOverview> {
  const { data } = await api.get<TicketOverview>('/admin/reports/ticket-overview', { params })
  return data
}

export async function fetchReportOptions(): Promise<ReportOptions> {
  const { data } = await api.get<{ data: ReportOptions }>('/admin/reports/options')
  return data.data
}

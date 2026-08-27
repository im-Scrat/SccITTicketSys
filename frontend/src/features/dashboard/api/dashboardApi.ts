import { api } from '@/services/api'
import type { DashboardPayload } from '../types'

/**
 * One endpoint for every role — the server picks the layout from the caller's
 * role and each widget's content from their permissions, so the client never
 * filters privileged data itself.
 */
export async function fetchDashboard(): Promise<DashboardPayload> {
  const { data } = await api.get<{ data: DashboardPayload }>('/dashboard/widgets')
  return data.data
}

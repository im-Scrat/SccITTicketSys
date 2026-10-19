import type { MaintenanceDetail } from '@/features/maintenance/types'
import { api } from '@/services/api'

/**
 * The real maintenance history behind a floor-plan node (WP-G) — full
 * records, not the timeline's or the summary tab's lossy projection of them.
 * `MaintenanceDetail` is the exact shape `MaintenanceDetailPage` already
 * renders one of; this fetches every visit against one machine.
 */
export async function fetchPcMaintenanceHistory(pcId: string): Promise<MaintenanceDetail[]> {
  const { data } = await api.get<{ data: MaintenanceDetail[] }>(
    `/admin/pc-units/${pcId}/maintenance`,
  )
  return data.data
}

import { isAxiosError } from 'axios'
import { useQuery } from '@tanstack/react-query'
import { fetchRoomPlan } from '../api/floorPlanApi'
import { fetchPcMaintenanceHistory } from '../api/pcInspectorApi'

export const floorPlanKeys = {
  all: ['floor-plan'] as const,
  room: (roomId: string) => ['floor-plan', 'room', roomId] as const,
  pcMaintenance: (pcId: string) => ['floor-plan', 'pc-maintenance', pcId] as const,
}

export function useRoomPlan(roomId: string | undefined) {
  return useQuery({
    queryKey: floorPlanKeys.room(roomId ?? ''),
    queryFn: () => fetchRoomPlan(roomId as string),
    enabled: Boolean(roomId),
    // A refusal or a missing room will not change on a second try; retrying
    // only delays the Forbidden / not-found screen.
    retry: (count, error) =>
      !(isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) && count < 1,
  })
}

/**
 * The real maintenance history for one PC unit (WP-G), fetched only once the
 * inspector actually opens for it — a viewer paging through a busy room never
 * pays for history no one asked to see.
 */
export function usePcMaintenanceHistory(pcId: string | undefined) {
  return useQuery({
    queryKey: floorPlanKeys.pcMaintenance(pcId ?? ''),
    queryFn: () => fetchPcMaintenanceHistory(pcId as string),
    enabled: Boolean(pcId),
    retry: (count, error) =>
      !(isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) && count < 1,
  })
}

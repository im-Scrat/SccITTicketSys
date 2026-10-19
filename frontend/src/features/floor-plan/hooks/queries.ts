import { isAxiosError } from 'axios'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { fetchFloorPlanRooms, fetchRoomPlan } from '../api/floorPlanApi'
import { fetchPcMaintenanceHistory } from '../api/pcInspectorApi'
import type { FloorPlanRoomsParams } from '../types'

export const floorPlanKeys = {
  all: ['floor-plan'] as const,
  rooms: (params: FloorPlanRoomsParams) => ['floor-plan', 'rooms', params] as const,
  room: (roomId: string) => ['floor-plan', 'room', roomId] as const,
  pcMaintenance: (pcId: string) => ['floor-plan', 'pc-maintenance', pcId] as const,
}

/** A refusal or a missing record will not change on a second try. */
const retryUnlessFinal = (count: number, error: unknown) =>
  !(isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) && count < 1

export function useFloorPlanRooms(params: FloorPlanRoomsParams) {
  return useQuery({
    queryKey: floorPlanKeys.rooms(params),
    queryFn: () => fetchFloorPlanRooms(params),
    placeholderData: keepPreviousData,
    retry: retryUnlessFinal,
    // The overview is a status board: refresh it now and then without a socket.
    refetchInterval: 60_000,
  })
}

/**
 * One room's plan. `pollWhenNotLive` keeps a viewer current when the realtime
 * channel is down (DD-16's polling fallback) — only while the draft is clean,
 * so a refetch can never pull the ground out from under an edit.
 */
export function useRoomPlan(roomId: string | undefined, options: { poll?: boolean } = {}) {
  return useQuery({
    queryKey: floorPlanKeys.room(roomId ?? ''),
    queryFn: () => fetchRoomPlan(roomId as string),
    enabled: Boolean(roomId),
    retry: retryUnlessFinal,
    refetchInterval: options.poll ? 60_000 : false,
    refetchOnWindowFocus: options.poll ?? false,
  })
}

/** Full maintenance history for one machine — fetched only once its details open. */
export function usePcMaintenanceHistory(pcId: string | undefined) {
  return useQuery({
    queryKey: floorPlanKeys.pcMaintenance(pcId ?? ''),
    queryFn: () => fetchPcMaintenanceHistory(pcId as string),
    enabled: Boolean(pcId),
    retry: retryUnlessFinal,
  })
}

import { useQuery } from '@tanstack/react-query'
import {
  lookupBuildings,
  lookupFloors,
  lookupRooms,
  type RoomLookupParams,
} from '@/services/lookups'

export const lookupKeys = {
  all: ['lookups'] as const,
  rooms: (params: RoomLookupParams) => ['lookups', 'rooms', params] as const,
  buildings: () => ['lookups', 'buildings'] as const,
  floors: (building?: string) => ['lookups', 'floors', building ?? 'all'] as const,
}

/**
 * Selectable rooms for a form's location field (FR-LOC-011). Cached for a minute
 * — the estate changes rarely, and a picker should feel instant.
 *
 * Keyed separately from the Administrator-only `locations` query namespace, so an
 * admin mutation and a reporter's picker never share a cache entry.
 */
export function useRoomLookup(params: RoomLookupParams = {}, enabled = true) {
  return useQuery({
    queryKey: lookupKeys.rooms(params),
    queryFn: () => lookupRooms(params),
    staleTime: 60_000,
    enabled,
  })
}

export function useBuildingLookup(enabled = true) {
  return useQuery({
    queryKey: lookupKeys.buildings(),
    queryFn: lookupBuildings,
    staleTime: 5 * 60_000,
    enabled,
  })
}

export function useFloorLookup(building?: string, enabled = true) {
  return useQuery({
    queryKey: lookupKeys.floors(building),
    queryFn: () => lookupFloors(building),
    staleTime: 5 * 60_000,
    enabled,
  })
}

import { useQuery } from '@tanstack/react-query'
import { lookupAssets, lookupPcUnits, lookupRoomEquipment } from '@/services/lookups'

export const equipmentLookupKeys = {
  all: ['lookups', 'equipment'] as const,
  pcUnits: (search?: string) => ['lookups', 'equipment', 'pc-units', search ?? ''] as const,
  assets: (search?: string) => ['lookups', 'equipment', 'assets', search ?? ''] as const,
  inRoom: (room: string) => ['lookups', 'equipment', 'room', room] as const,
}

/**
 * Selectable PC units for a form's equipment field (SDD DD-38).
 *
 * Keyed outside the Administrator-only `assets` query namespace, exactly as the
 * room lookup is kept outside `locations`: an administrator's asset mutation and
 * a teacher's picker must never share a cache entry, or the picker would start
 * serving whatever the admin surface last loaded.
 */
export function usePcUnitLookup(search?: string, enabled = true) {
  return useQuery({
    queryKey: equipmentLookupKeys.pcUnits(search),
    queryFn: () => lookupPcUnits(search),
    staleTime: 60_000,
    enabled,
  })
}

/**
 * Selectable serialized assets for a form's component field.
 *
 * Kept in the same cache namespace as the PC lookup and outside `assets` for the
 * same reason: an administrator's register query and a technician's picker must
 * never share an entry.
 */
export function useAssetLookup(search?: string, enabled = true) {
  return useQuery({
    queryKey: equipmentLookupKeys.assets(search),
    queryFn: () => lookupAssets(search),
    staleTime: 60_000,
    enabled,
  })
}

/**
 * One room's reportable equipment for the report form (SDD DD-78).
 *
 * The query's AbortSignal is handed to the request, so a room left before its
 * list arrived cancels the request instead of racing the next one. Callers pass
 * a debounced room: a keyboard user arrowing through the room list should cause
 * one lookup for where they stop.
 */
export function useRoomEquipmentLookup(room?: string) {
  return useQuery({
    queryKey: equipmentLookupKeys.inRoom(room ?? ''),
    queryFn: ({ signal }) => lookupRoomEquipment(room as string, signal),
    staleTime: 60_000,
    enabled: Boolean(room),
  })
}

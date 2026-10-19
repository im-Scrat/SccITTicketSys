import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  archiveLocation,
  type BuildingPayload,
  createBuilding,
  createFloor,
  createRoom,
  type FloorPayload,
  type LocationLevel,
  reassignRoomOccupants,
  restoreLocation,
  type RoomPayload,
  setLocationActive,
  updateBuilding,
  updateFloor,
  updateRoom,
} from '../api/locationsApi'
import { locationsKeys } from './queries'

/**
 * Invalidate the whole locations namespace. Any write can change the tree, the
 * metrics, both directories and the picker at once (archiving a building hides
 * its rooms everywhere), so a namespace-wide invalidation is both simplest and
 * correct — the queries are small and cheap to refetch.
 */
function useInvalidateLocations() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: locationsKeys.all })
}

export function useCreateBuilding() {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (payload: BuildingPayload) => createBuilding(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateBuilding(id: string) {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (payload: BuildingPayload) => updateBuilding(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useCreateFloor(buildingId: string) {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (payload: FloorPayload) => createFloor(buildingId, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateFloor(id: string) {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (payload: FloorPayload) => updateFloor(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useCreateRoom() {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (payload: RoomPayload) => createRoom(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateRoom(id: string) {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (payload: RoomPayload) => updateRoom(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useSetLocationActive() {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (vars: { level: 'buildings' | 'rooms'; id: string; active: boolean }) =>
      setLocationActive(vars.level, vars.id, vars.active),
    onSuccess: () => void invalidate(),
  })
}

/** Archive. Rejects with the 422 `location_in_use` payload when blocked. */
export function useArchiveLocation() {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (vars: { level: LocationLevel; id: string }) =>
      archiveLocation(vars.level, vars.id),
    onSuccess: () => void invalidate(),
  })
}

export function useRestoreLocation() {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (vars: { level: LocationLevel; id: string }) =>
      restoreLocation(vars.level, vars.id),
    onSuccess: () => void invalidate(),
  })
}

export function useReassignRoomOccupants(id: string) {
  const invalidate = useInvalidateLocations()
  return useMutation({
    mutationFn: (toRoom: string) => reassignRoomOccupants(id, toRoom),
    onSuccess: () => void invalidate(),
  })
}

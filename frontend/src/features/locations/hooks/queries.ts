import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchBuilding,
  fetchLocationAudit,
  fetchLocationMetrics,
  fetchLocationTree,
  fetchRoom,
  listBuildings,
  listFloors,
  listRooms,
  type LocationLevel,
} from '../api/locationsApi'
import type { BuildingParams, RoomParams } from '../types'

export const locationsKeys = {
  all: ['locations'] as const,
  metrics: () => ['locations', 'metrics'] as const,
  tree: () => ['locations', 'tree'] as const,
  buildings: (params: BuildingParams) => ['locations', 'buildings', params] as const,
  building: (id: string) => ['locations', 'building', id] as const,
  floors: (buildingId: string, trashed: string) =>
    ['locations', 'floors', buildingId, trashed] as const,
  rooms: (params: RoomParams) => ['locations', 'rooms', params] as const,
  room: (id: string) => ['locations', 'room', id] as const,
  audit: (level: LocationLevel, id: string, page: number) =>
    ['locations', 'audit', level, id, page] as const,
}

export function useLocationMetrics() {
  return useQuery({ queryKey: locationsKeys.metrics(), queryFn: fetchLocationMetrics })
}

export function useLocationTree() {
  return useQuery({ queryKey: locationsKeys.tree(), queryFn: fetchLocationTree })
}

export function useBuildingsList(params: BuildingParams) {
  return useQuery({
    queryKey: locationsKeys.buildings(params),
    queryFn: () => listBuildings(params),
    placeholderData: keepPreviousData, // smooth page/filter transitions
  })
}

export function useBuilding(id: string | undefined) {
  return useQuery({
    queryKey: locationsKeys.building(id ?? ''),
    queryFn: () => fetchBuilding(id as string),
    enabled: Boolean(id),
  })
}

export function useFloors(buildingId: string | undefined, trashed = 'without') {
  return useQuery({
    queryKey: locationsKeys.floors(buildingId ?? '', trashed),
    queryFn: () => listFloors(buildingId as string, trashed),
    enabled: Boolean(buildingId),
  })
}

export function useRoomsList(params: RoomParams, enabled = true) {
  return useQuery({
    queryKey: locationsKeys.rooms(params),
    queryFn: () => listRooms(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useRoom(id: string | undefined) {
  return useQuery({
    queryKey: locationsKeys.room(id ?? ''),
    queryFn: () => fetchRoom(id as string),
    enabled: Boolean(id),
  })
}

export function useLocationAudit(level: LocationLevel, id: string | undefined, page = 1) {
  return useQuery({
    queryKey: locationsKeys.audit(level, id ?? '', page),
    queryFn: () => fetchLocationAudit(level, id as string, page),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  })
}

/*
 * Option lookups (rooms/buildings/floors for a select) are shared, not part of
 * this slice: `useRoomLookup` / `useBuildingLookup` / `useFloorLookup` in
 * `@/hooks/useLocationLookup`. This module's own drawers and dialogs use them too,
 * so there is exactly one lookup implementation for admins and forms alike.
 */

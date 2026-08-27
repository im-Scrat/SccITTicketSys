import { api } from '@/services/api'
import type {
  BuildingDetail,
  BuildingListItem,
  BuildingParams,
  Detail,
  FloorItem,
  LocationMetrics,
  LocationTreeNode,
  Paginated,
  RoomDetail,
  RoomListItem,
  RoomParams,
  RoomType,
} from '../types'

/** Drop empty values so they never reach the query string as `?search=`. */
function cleanParams(params: object): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[key] = value as string | number
  }
  return out
}

/* ---------------------------------------------------------------- reads */

export async function fetchLocationMetrics(): Promise<LocationMetrics> {
  const { data } = await api.get<{ data: LocationMetrics }>('/admin/locations/dashboard')
  return data.data
}

export async function fetchLocationTree(): Promise<LocationTreeNode[]> {
  const { data } = await api.get<{ data: LocationTreeNode[] }>('/admin/locations/tree')
  return data.data
}

export async function listBuildings(params: BuildingParams): Promise<Paginated<BuildingListItem>> {
  const { data } = await api.get<Paginated<BuildingListItem>>('/admin/buildings', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchBuilding(id: string): Promise<Detail<BuildingDetail>> {
  const { data } = await api.get<Detail<BuildingDetail>>(`/admin/buildings/${id}`)
  return data
}

export async function listFloors(buildingId: string, trashed = 'without'): Promise<FloorItem[]> {
  const { data } = await api.get<{ data: FloorItem[] }>(`/admin/buildings/${buildingId}/floors`, {
    params: { trashed },
  })
  return data.data
}

export async function listRooms(params: RoomParams): Promise<Paginated<RoomListItem>> {
  const { data } = await api.get<Paginated<RoomListItem>>('/admin/rooms', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchRoom(id: string): Promise<Detail<RoomDetail>> {
  const { data } = await api.get<Detail<RoomDetail>>(`/admin/rooms/${id}`)
  return data
}

export type LocationLevel = 'buildings' | 'floors' | 'rooms'

export interface AuditEntry {
  id: number
  action: string
  label: string
  description: string | null
  module: string | null
  actor?: { id: string; name: string } | null
  properties: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

export async function fetchLocationAudit(
  level: LocationLevel,
  id: string,
  page = 1,
): Promise<Paginated<AuditEntry>> {
  const { data } = await api.get<Paginated<AuditEntry>>(`/admin/${level}/${id}/audit`, {
    params: { page },
  })
  return data
}

/*
 * Room/building/floor option lookups are NOT here: they live in
 * `@/services/lookups` + `@/hooks/useLocationLookup`, because a non-admin form
 * needs them and must never import this admin-only slice (FR-LOC-011). This
 * module's own forms consume the same shared hooks.
 */

/* --------------------------------------------------------------- writes */

export interface BuildingPayload {
  name: string
  code: string
  description?: string
  address?: string
}

export async function createBuilding(payload: BuildingPayload): Promise<BuildingDetail> {
  const { data } = await api.post<Detail<BuildingDetail>>('/admin/buildings', payload)
  return data.data
}

export async function updateBuilding(
  id: string,
  payload: BuildingPayload,
): Promise<BuildingDetail> {
  const { data } = await api.put<Detail<BuildingDetail>>(`/admin/buildings/${id}`, payload)
  return data.data
}

export interface FloorPayload {
  floor_number: number
  name: string
  description?: string
}

export async function createFloor(buildingId: string, payload: FloorPayload): Promise<FloorItem> {
  const { data } = await api.post<{ data: FloorItem }>(
    `/admin/buildings/${buildingId}/floors`,
    payload,
  )
  return data.data
}

export async function updateFloor(id: string, payload: FloorPayload): Promise<FloorItem> {
  const { data } = await api.put<{ data: FloorItem }>(`/admin/floors/${id}`, payload)
  return data.data
}

export interface RoomPayload {
  floor?: string
  name: string
  code: string
  room_number?: string
  room_type: RoomType
  capacity: number | null
  description?: string
}

export async function createRoom(payload: RoomPayload): Promise<RoomDetail> {
  const { data } = await api.post<Detail<RoomDetail>>('/admin/rooms', payload)
  return data.data
}

export async function updateRoom(id: string, payload: RoomPayload): Promise<RoomDetail> {
  const { data } = await api.put<Detail<RoomDetail>>(`/admin/rooms/${id}`, payload)
  return data.data
}

/** Activate / deactivate a building or room (floors have no active flag). */
export async function setLocationActive(
  level: 'buildings' | 'rooms',
  id: string,
  active: boolean,
): Promise<void> {
  await api.post(`/admin/${level}/${id}/${active ? 'activate' : 'deactivate'}`)
}

/** Archive (soft delete). Throws the 422 `location_in_use` payload when blocked. */
export async function archiveLocation(level: LocationLevel, id: string): Promise<void> {
  await api.delete(`/admin/${level}/${id}`)
}

export async function restoreLocation(level: LocationLevel, id: string): Promise<void> {
  await api.post(`/admin/${level}/${id}/restore`)
}

export async function reassignRoomOccupants(
  id: string,
  toRoom: string,
): Promise<{ message: string; moved: { pc_units: number; assets: number; consumables: number } }> {
  const { data } = await api.post<{
    message: string
    moved: { pc_units: number; assets: number; consumables: number }
  }>(`/admin/rooms/${id}/reassign`, { to_room: toRoom })
  return data
}

import { api } from '@/services/api'
import type {
  FloorPlanRoomsParams,
  FloorPlanRoomsResponse,
  LayoutCreateRequest,
  PlanLayoutRecord,
  RoomPlan,
  SaveLayoutRequest,
} from '../types'

/**
 * Every live room with its machines' repair status and its Floorplan's state
 * — the overview's cards (Phase 2.11).
 */
export async function fetchFloorPlanRooms(
  params: FloorPlanRoomsParams,
): Promise<FloorPlanRoomsResponse> {
  const { data } = await api.get<FloorPlanRoomsResponse>('/admin/floor-plan/rooms', { params })
  return data
}

/**
 * One room's active layout, its fixtures and the machines on it. The server is
 * the authority on who may call this: a non-administrator gets a 403 whatever
 * the client believes.
 */
export async function fetchRoomPlan(roomId: string): Promise<RoomPlan> {
  const { data } = await api.get<{ data: RoomPlan }>(`/admin/floor-plan/rooms/${roomId}`)
  return data.data
}

/**
 * Save the whole layout in one transaction (SDD DD-101). Answers with the plan
 * exactly as stored; a 409 `layout_stale` carries the current plan instead.
 */
export async function saveRoomLayout(
  roomId: string,
  version: number,
  request: SaveLayoutRequest,
): Promise<RoomPlan> {
  const { data } = await api.put<{ data: RoomPlan }>(
    `/admin/floor-plan/rooms/${roomId}/layouts/${version}`,
    request,
  )
  return data.data
}

/** Start a layout version; `activate: true` sets a room's first Floorplan up in one step. */
export async function createRoomLayout(
  roomId: string,
  request: LayoutCreateRequest,
): Promise<PlanLayoutRecord> {
  const { data } = await api.post<{ data: PlanLayoutRecord }>(
    `/admin/floor-plan/rooms/${roomId}/layouts`,
    request,
  )
  return data.data
}

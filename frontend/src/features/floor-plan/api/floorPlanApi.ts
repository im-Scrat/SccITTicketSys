import { api } from '@/services/api'
import type {
  LayoutCreateRequest,
  PlacedPc,
  PlacementRequest,
  PlanLayoutRecord,
  RoomPlan,
} from '../types'

/**
 * One room's active layout and the PC units placed on it.
 *
 * The server is the authority on who may call this: a non-administrator gets a
 * 403 whatever the client believes about their permissions.
 */
export async function fetchRoomPlan(roomId: string): Promise<RoomPlan> {
  const { data } = await api.get<{ data: RoomPlan }>(`/admin/floor-plan/rooms/${roomId}`)
  return data.data
}

/**
 * Place or move a unit on a layout version of a room.
 *
 * The answer is the **stored** point — snapped and clamped by the server — and
 * is what the map must show afterwards, not the point that was sent.
 */
export async function placePcUnit(
  roomId: string,
  version: number,
  pcId: string,
  request: PlacementRequest,
): Promise<PlacedPc> {
  const { data } = await api.patch<{ data: PlacedPc }>(
    `/admin/floor-plan/rooms/${roomId}/layouts/${version}/positions/${pcId}`,
    request,
  )
  return data.data
}

/**
 * Start a new layout version for a room (FR-FP-001/008). The server numbers it
 * and returns it **inactive**; nothing is shown on the map until it is
 * activated.
 */
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

/** Make one version the room's active layout. Positions of the previous version stay with it. */
export async function activateRoomLayout(
  roomId: string,
  version: number,
): Promise<PlanLayoutRecord> {
  const { data } = await api.post<{ data: PlanLayoutRecord }>(
    `/admin/floor-plan/rooms/${roomId}/layouts/${version}/activate`,
  )
  return data.data
}

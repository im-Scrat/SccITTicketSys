import { api } from '@/services/api'
import type { PlacedPc, PlacementRequest, RoomPlan } from '../types'

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

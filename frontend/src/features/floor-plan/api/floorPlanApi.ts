import { api } from '@/services/api'
import type { RoomPlan } from '../types'

/**
 * One room's active layout and the PC units placed on it. Read-only.
 *
 * The server is the authority on who may call this: a non-administrator gets a
 * 403 whatever the client believes about their permissions.
 */
export async function fetchRoomPlan(roomId: string): Promise<RoomPlan> {
  const { data } = await api.get<{ data: RoomPlan }>(`/admin/floor-plan/rooms/${roomId}`)
  return data.data
}

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { updatePcUnit } from '@/features/assets/api/assetsApi'
import { createRoomLayout, saveRoomLayout } from '../api/floorPlanApi'
import type { LayoutCreateRequest, PcStatus, RoomPlan, SaveLayoutRequest } from '../types'
import { floorPlanKeys } from './queries'

/**
 * Save the whole layout. The overview is refreshed for its counts and
 * thumbnail; the room's own cached plan is replaced by the *caller*, after it
 * has recorded the new revision as its own — updating the cache here first
 * would let the editor mistake its own save for someone else's.
 */
export function useSaveLayout(roomId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ version, request }: { version: number; request: SaveLayoutRequest }) =>
      saveRoomLayout(roomId, version, request),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['floor-plan', 'rooms'] }),
  })
}

/** Set up a room's first Floorplan (create and activate in one step). */
export function useCreateLayout(roomId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (request: LayoutCreateRequest) =>
      createRoomLayout(roomId, { ...request, activate: true }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: floorPlanKeys.all }),
  })
}

/**
 * Move a PC registered elsewhere into this room — the **existing** asset room
 * change (`PUT /admin/pc-units/{uuid}` with `room`, gated by `assets.update`
 * and audited), never a Floorplan write. Its old room's plan releases it
 * (DD-104); here it arrives unplaced.
 */
export function useMovePcToRoom(roomId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (pcId: string) => updatePcUnit(pcId, { room: roomId }),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: floorPlanKeys.all }),
        queryClient.invalidateQueries({ queryKey: ['assets'] }),
      ]),
  })
}

/** Merge a live status change into the cached plan — status is never part of the draft. */
export function withStatusChanged(plan: RoomPlan, pcId: string, status: PcStatus): RoomPlan {
  return {
    ...plan,
    pcs: plan.pcs.map((pc) => (pc.id === pcId ? { ...pc, status } : pc)),
    unplaced: plan.unplaced.map((pc) => (pc.id === pcId ? { ...pc, status } : pc)),
  }
}

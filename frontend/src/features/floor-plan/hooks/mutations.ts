import { isAxiosError } from 'axios'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { placePcUnit } from '../api/floorPlanApi'
import type { PlacedPc, PlacementRequest, RoomPlan, StaleWriteConflict, UnplacedPc } from '../types'
import { floorPlanKeys } from './queries'

export interface PlaceVariables {
  version: number
  pcId: string
  request: PlacementRequest
  /** Where the unit is shown while the request is in flight (the client's preview). */
  preview: { x: number; y: number }
}

interface PlaceContext {
  /** The unit as it was before this move — to put back if the server refuses. */
  before: PlacedPc | undefined
  /** Set when the unit was not on the plan yet. */
  wasUnplaced: UnplacedPc | undefined
}

/**
 * Place or move a unit, optimistically.
 *
 * The unit is drawn at the preview straight away, then **reconciled to the
 * server's answer** — the stored point, which may differ from the preview when
 * the server snapped or clamped it. The server is the only coordinate
 * authority; the preview is never kept once the answer arrives.
 *
 * Rollback is **per unit**, not a whole-plan snapshot: two moves can be in
 * flight at once, and restoring a snapshot taken for the first would silently
 * undo the second.
 *
 * A 404 (the unit left the room) or a 409 for a no-longer-active layout means
 * the cached plan is out of date, so it is refetched as well. A 409 for a
 * **stale position** (WP-F, D3 — another admin moved this exact unit since
 * this browser last read it) reconciles straight to the `current` state the
 * server's refusal already carries, rather than a refetch: the server told
 * this browser the truth in the same response, so asking again would only
 * spend a round trip confirming it.
 */
export function usePlacePcUnit(roomId: string) {
  const queryClient = useQueryClient()
  const key = floorPlanKeys.room(roomId)

  return useMutation<PlacedPc, unknown, PlaceVariables, PlaceContext>({
    mutationFn: ({ version, pcId, request }) => placePcUnit(roomId, version, pcId, request),

    onMutate: async ({ pcId, preview }) => {
      await queryClient.cancelQueries({ queryKey: key })
      const plan = queryClient.getQueryData<RoomPlan>(key)
      const before = plan?.pcs.find((pc) => pc.id === pcId)
      const wasUnplaced = plan?.unplaced.find((pc) => pc.id === pcId)

      const optimistic: PlacedPc | undefined = before
        ? { ...before, ...preview }
        : wasUnplaced
          ? // A never-placed unit has no updated_at yet; the placeholder here
            // is overwritten by the server's real value in onSuccess/onError
            // before anything could read it back as an expected_updated_at.
            {
              ...wasUnplaced,
              ...preview,
              rotation: 0,
              z_index: 0,
              updated_at: new Date().toISOString(),
            }
          : undefined

      if (optimistic) {
        queryClient.setQueryData<RoomPlan>(key, (current) =>
          current ? withPlaced(current, optimistic) : current,
        )
      }

      return { before, wasUnplaced }
    },

    onError: (error, { pcId }, context) => {
      const conflict = staleWriteConflict(error)

      queryClient.setQueryData<RoomPlan>(key, (current) => {
        if (!current) return current
        // The server already told us the truth — reconcile to exactly that,
        // rather than the (now equally stale) pre-drag snapshot.
        if (conflict) return withPlaced(current, conflict.current)
        if (!context) return current
        if (context.before) return withPlaced(current, context.before)
        if (context.wasUnplaced) return withUnplaced(current, context.wasUnplaced)
        return withoutUnit(current, pcId)
      })

      const status = isAxiosError(error) ? error.response?.status : undefined
      if (!conflict && (status === 404 || status === 409)) {
        void queryClient.invalidateQueries({ queryKey: key })
      }
    },

    onSuccess: (placed) => {
      queryClient.setQueryData<RoomPlan>(key, (current) =>
        current ? withPlaced(current, placed) : current,
      )
    },
  })
}

/** The plan with this unit placed (or moved) — and no longer listed as unplaced. */
export function withPlaced(plan: RoomPlan, placed: PlacedPc): RoomPlan {
  const exists = plan.pcs.some((pc) => pc.id === placed.id)
  const unplaced = plan.unplaced.filter((pc) => pc.id !== placed.id)

  return {
    ...plan,
    pcs: exists ? plan.pcs.map((pc) => (pc.id === placed.id ? placed : pc)) : [...plan.pcs, placed],
    unplaced,
    unplaced_count: unplaced.length,
  }
}

/**
 * The plan with one unit's status updated in place — wherever it currently
 * sits (placed or unplaced), leaving its position untouched. Used both by the
 * WP-E broadcast reconciliation and available for a future direct fetch of
 * the same shape, so a status merge is written once.
 */
export function withStatusChanged(
  plan: RoomPlan,
  changed: Pick<PlacedPc, 'id' | 'name' | 'unit_code' | 'status'>,
): RoomPlan {
  let touched = false

  const pcs = plan.pcs.map((pc) => {
    if (pc.id !== changed.id) return pc
    touched = true
    return { ...pc, name: changed.name, unit_code: changed.unit_code, status: changed.status }
  })

  const unplaced = plan.unplaced.map((pc) => {
    if (pc.id !== changed.id) return pc
    touched = true
    return { ...pc, name: changed.name, unit_code: changed.unit_code, status: changed.status }
  })

  // The unit is neither placed nor unplaced on *this* layout (a different
  // room, or a machine this viewer's plan never listed) — nothing to merge.
  return touched ? { ...plan, pcs, unplaced } : plan
}

function withUnplaced(plan: RoomPlan, unit: UnplacedPc): RoomPlan {
  const pcs = plan.pcs.filter((pc) => pc.id !== unit.id)
  const unplaced = plan.unplaced.some((pc) => pc.id === unit.id)
    ? plan.unplaced
    : [...plan.unplaced, unit].sort((a, b) => a.name.localeCompare(b.name))

  return { ...plan, pcs, unplaced, unplaced_count: unplaced.length }
}

function withoutUnit(plan: RoomPlan, pcId: string): RoomPlan {
  return { ...plan, pcs: plan.pcs.filter((pc) => pc.id !== pcId) }
}

/** Narrows a refusal to the WP-F stale-write shape, or null for any other error. */
function staleWriteConflict(error: unknown): StaleWriteConflict | null {
  if (!isAxiosError(error) || error.response?.status !== 409) return null
  const data = error.response.data as Partial<StaleWriteConflict> | undefined
  return data?.code === 'position_stale' && data.current ? (data as StaleWriteConflict) : null
}

import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho } from '@/services/echo'
import { formatCoordinate } from '../lib/placement'
import type { PlacedPc, RoomPlan } from '../types'
import { withPlaced, withStatusChanged } from './mutations'
import { floorPlanKeys } from './queries'

/** The narrow shape the two floor-plan broadcasts carry (WP-E; FR-FP-007). */
interface PositionUpdatedPayload {
  layout_version: number
  pc: PlacedPc
}

interface PcStatusChangedPayload {
  pc: Pick<PlacedPc, 'id' | 'name' | 'unit_code' | 'status'>
}

interface FloorPlanChannelOptions {
  /** The layout currently on screen. A broadcast for a different version is ignored. */
  layoutVersion: number | undefined
  /**
   * A unit this browser is itself mid-move on. Its own `onSuccess` is the
   * authoritative reconciliation for that unit; applying an echo of the same
   * move on top would either be redundant or, if the two race, could stamp a
   * fresher local preview with an older broadcast. Re-evaluated on every
   * event — pass a function, not a snapshot.
   */
  isOwnPendingMove: (pcId: string) => boolean
  /** Told about a remote change, for parity with the announcement a local move gets. */
  announce?: (message: string) => void
}

/**
 * Subscribe to `private-floor-plan.room.{roomId}` and keep the room's cached
 * plan in step with what every other viewer's browser is doing (WP-E).
 *
 * **Cache reconciliation, not a second source of truth.** Both handlers write
 * through the same `withPlaced`/`withStatusChanged` helpers the optimistic
 * mutation uses (`hooks/mutations.ts`), so a broadcast and a locally-applied
 * write update the query cache identically. There is no floor-plan state
 * anywhere outside TanStack Query's cache for this room.
 *
 * **Own-echo handling.** The server broadcasts to every subscriber of the
 * room's channel, including the browser that made the move — so this browser
 * receives its own `PositionUpdated`. `isOwnPendingMove` lets the caller skip
 * applying that echo while its own mutation is still in flight; once the
 * mutation resolves, its own `onSuccess` has already set the authoritative
 * value, and any later echo for that unit is simply idempotent.
 *
 * The subscription depends only on `roomId`: `layoutVersion` and
 * `isOwnPendingMove` are read through a ref on every event, so a re-render
 * that changes them (a fresh closure, a new layout version) never tears down
 * and re-opens the socket subscription.
 */
export function useFloorPlanChannel(
  roomId: string | undefined,
  options: FloorPlanChannelOptions,
): void {
  const queryClient = useQueryClient()
  const optionsRef = useRef(options)
  optionsRef.current = options

  useEffect(() => {
    if (!roomId) return

    let cancelled = false
    let subscribed: string | null = null

    getEcho()
      .then((echo) => {
        if (cancelled) return
        subscribed = `floor-plan.room.${roomId}`
        const channel = echo.private(subscribed)

        channel.listen('.floor-plan.position-updated', (payload: PositionUpdatedPayload) => {
          const { layoutVersion, isOwnPendingMove, announce } = optionsRef.current
          if (layoutVersion !== undefined && payload.layout_version !== layoutVersion) return
          if (isOwnPendingMove(payload.pc.id)) return

          queryClient.setQueryData<RoomPlan>(floorPlanKeys.room(roomId), (current) =>
            current ? withPlaced(current, payload.pc) : current,
          )
          announce?.(
            `${payload.pc.name} moved to x ${formatCoordinate(payload.pc.x)}, y ${formatCoordinate(payload.pc.y)}.`,
          )
        })

        channel.listen('.floor-plan.pc-status-changed', (payload: PcStatusChangedPayload) => {
          queryClient.setQueryData<RoomPlan>(floorPlanKeys.room(roomId), (current) =>
            current ? withStatusChanged(current, payload.pc) : current,
          )
          optionsRef.current.announce?.(`${payload.pc.name} is now ${payload.pc.status.label}.`)
        })
      })
      .catch(() => {
        // The map still works from fetches; a socket that never connects
        // (offline, Reverb down) degrades to "not live", not broken.
      })

    return () => {
      cancelled = true
      if (subscribed) void getEcho().then((echo) => echo.leave(subscribed!))
    }
  }, [roomId, queryClient])
}

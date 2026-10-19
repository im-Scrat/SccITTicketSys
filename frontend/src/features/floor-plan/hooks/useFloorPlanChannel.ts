import { useState } from 'react'
import { usePrivateChannel } from '@/hooks/usePrivateChannel'
import type { PcStatusChangedEvent, RoomPlanChangedEvent } from '../types'

interface FloorPlanChannelHandlers {
  /** Someone saved, a version was activated, or a machine joined or left the room. */
  onPlanChanged: (event: RoomPlanChangedEvent) => void
  /** A machine's repair status moved (a person, a ticket or a repair). */
  onStatusChanged: (event: PcStatusChangedEvent) => void
}

/**
 * The room's realtime notices (FR-FP-007; SDD DD-101). Notices only — the
 * editor decides what to do with each (refetch when clean, offer to combine
 * when dirty). Returns whether the channel is live, so the page can say so
 * honestly instead of implying updates that are not arriving.
 */
export function useFloorPlanChannel(
  roomId: string | undefined,
  handlers: FloorPlanChannelHandlers,
) {
  const [live, setLive] = useState(false)

  usePrivateChannel(
    roomId ? `floor-plan.room.${roomId}` : undefined,
    {
      '.floor-plan.room-plan-changed': (event: RoomPlanChangedEvent) =>
        handlers.onPlanChanged(event),
      '.floor-plan.pc-status-changed': (event: PcStatusChangedEvent) =>
        handlers.onStatusChanged(event),
    },
    { onLiveChange: setLive },
  )

  return live
}

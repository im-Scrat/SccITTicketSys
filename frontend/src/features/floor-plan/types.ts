/**
 * The floor-plan map payload (`GET /api/admin/floor-plan/rooms/{room}`).
 *
 * Status **label and tone come from the server** (`PcStatus::label()/tone()`),
 * so the wording and the colour decision live in one place and cannot drift
 * between the map, the legend and the rest of the app.
 */

/** Presentation tone the server decides, so the client never picks a status colour. */
export type Tone = 'neutral' | 'success' | 'warning' | 'danger'

/** Mirrors the backend `PcStatus` enum. */
export type PcStatusValue =
  'available' | 'assigned' | 'online' | 'offline' | 'under_maintenance' | 'retired'

export interface PcStatus {
  value: PcStatusValue
  label: string
  tone: Tone
}

export interface PlacedPc {
  id: string
  name: string
  unit_code: string
  status: PcStatus
  /** Layout-local pixels, origin top-left, matching `layout.width/height`. */
  x: number
  y: number
  rotation: number
  z_index: number
}

export interface PlanLayout {
  version: number
  width: number
  height: number
  grid_size: number
}

export interface RoomPlan {
  room: {
    id: string
    name: string
    code: string
    floor: { name: string; floor_number: number } | null
    building: { name: string; code: string } | null
  }
  /** Null when the room has no active layout yet. */
  layout: PlanLayout | null
  pcs: PlacedPc[]
  /** Live PC units in the room that have no position on this layout. */
  unplaced_count: number
}

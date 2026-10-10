/**
 * The floor-plan map payload (`GET /api/admin/floor-plan/rooms/{room}`).
 *
 * Status **label and tone come from the server** (`PcStatus::label()/tone()`),
 * so the wording and the colour decision live in one place and cannot drift
 * between the map, the legend and the rest of the app.
 */

/** Presentation tone the server decides, so the client never picks a status colour. */
export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

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
  /**
   * The optimistic-concurrency token (WP-F, D3). Echoed back as
   * `expected_updated_at` on the next move of this unit; the server refuses
   * with 409 when it no longer matches what is actually stored.
   */
  updated_at: string
  /** The newest open ticket on this machine, if any — the map's "alert" and tooltip detail. */
  open_ticket: { id: string; reference: string } | null
}

/** A unit of the room with no position on this layout yet. */
export interface UnplacedPc {
  id: string
  name: string
  unit_code: string
  status: PcStatus
}

export interface PlanLayout {
  version: number
  width: number
  height: number
  grid_size: number
}

/**
 * What the client may offer. A courtesy only: every write is authorized again
 * on the server, which answers a refused edit with a 403 whatever this says.
 */
export interface PlanEditor {
  can_edit: boolean
  /** The `floor_plan.snap_to_grid` default. */
  snap_to_grid: boolean
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
  unplaced: UnplacedPc[]
  /** Live PC units in the room that have no position on this layout. */
  unplaced_count: number
  editor: PlanEditor
}

/** What an editor asks for when starting a layout version. The server applies the grid default when omitted. */
export interface LayoutCreateRequest {
  width: number
  height: number
  grid_size?: number | null
}

/** A layout version as the create/activate endpoints return it. */
export interface PlanLayoutRecord extends PlanLayout {
  is_active: boolean
}

/** Where a unit was aimed. The server snaps (unless `snap` is false), clamps and stores. */
export interface PlacementRequest {
  x: number
  y: number
  snap: boolean
  /** This unit's last-known `updated_at`, or null for a unit never placed before. */
  expected_updated_at: string | null
}

/** The server's answer to a refused, stale write (WP-F, D3): the current truth to reconcile to. */
export interface StaleWriteConflict {
  code: 'position_stale'
  message: string
  current: PlacedPc
}

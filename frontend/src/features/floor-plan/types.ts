/**
 * The Floorplan API contract (Phase 2.11).
 *
 * Status **label and tone come from the server** (`PcStatus::label()/tone()`),
 * mirrored by the shared table in `@/lib/pcStatus` — one vocabulary on every
 * screen. Coordinates are layout units with the origin top-left; a position is
 * the **centre** of its shape.
 */

import type { PcStatusValue } from '@/lib/pcStatus'

export type { PcStatusValue }

/** Presentation tone the server decides — one per repair status. */
export type Tone = 'success' | 'warning' | 'danger'

export interface PcStatus {
  value: PcStatusValue
  label: string
  tone: Tone
}

/** A machine placed on the layout. */
export interface PlacedPc {
  id: string
  name: string
  unit_code: string
  status: PcStatus
  x: number
  y: number
  /** Quarter turns only: 0, 90, 180 or 270. */
  rotation: number
  z_index: number
  updated_at: string
  /** The newest open ticket on this machine, if any. */
  open_ticket: { id: string; reference: string } | null
}

/** A machine of the room with no place on this layout yet. */
export interface UnplacedPc {
  id: string
  name: string
  unit_code: string
  status: PcStatus
}

/**
 * Equipment a custodian confirmed is in this room (owner request 2026-10-20,
 * SDD DD-115): printers, projectors, UPS… — assets that are not PCs. The
 * status is the asset's lifecycle status, label and tone from the server.
 */
export interface EquipmentStatus {
  value: string
  label: string
  tone: 'neutral' | 'success' | 'warning' | 'danger'
}

export interface UnplacedEquipment {
  id: string
  name: string
  /** "Printer", or the words typed for an "Other" category. */
  category: string | null
  status: EquipmentStatus
}

export interface PlacedEquipment extends UnplacedEquipment {
  x: number
  y: number
  /** Quarter turns only, as for a workstation. */
  rotation: number
  z_index: number
}

export const FIXTURE_KINDS = [
  'zone',
  'desk',
  'cabinet',
  'wall',
  'partition',
  'pillar',
  'door',
  'window',
] as const

export type FixtureKind = (typeof FIXTURE_KINDS)[number]

/** A layout-only shape (desk, wall, door…) — never an asset (SDD DD-102). */
export interface Fixture {
  /** Client-generated uuid, unique per layout. */
  id: string
  kind: FixtureKind
  x: number
  y: number
  width: number
  height: number
  rotation: number
  label: string | null
}

export interface PlanLayout {
  version: number
  /** The whole-layout concurrency token, echoed as `expected_revision`. */
  revision: number
  width: number
  height: number
  grid_size: number
  updated_at: string | null
  /** A display name only — never an id or an email. */
  updated_by: { name: string } | null
}

/** What the client may offer; every save is authorized again on the server. */
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
  fixtures: Fixture[]
  /** A workstation's square footprint, in layout units. */
  workstation_size: number
  pcs: PlacedPc[]
  unplaced: UnplacedPc[]
  unplaced_count: number
  /** Confirmed equipment placed on this layout (DD-115). */
  equipment?: PlacedEquipment[]
  /** Confirmed equipment in the room with no place yet. */
  unplaced_equipment?: UnplacedEquipment[]
  editor: PlanEditor
}

/** Starting a room's layout. `activate` sets it up in one step. */
export interface LayoutCreateRequest {
  width: number
  height: number
  grid_size?: number | null
  activate?: boolean
}

export interface PlanLayoutRecord {
  version: number
  width: number
  height: number
  grid_size: number
  is_active: boolean
}

/** The whole Floorplan, as `PUT …/layouts/{version}` takes it (SDD DD-101). */
export interface SaveLayoutRequest {
  expected_revision: number
  settings: { width: number; height: number; grid_size: number }
  workstations: { pc_unit: string; x: number; y: number; rotation: number }[]
  fixtures: Fixture[]
  /** Confirmed equipment's places (DD-115); omitted, the server keeps them. */
  equipment?: { asset: string; x: number; y: number; rotation: number }[]
}

/** 409 `layout_stale`: someone saved since the editor loaded — here is the current plan. */
export interface LayoutStaleConflict {
  code: 'layout_stale'
  message: string
  current: RoomPlan
}

/** `RoomPlanChanged` on `floor-plan.room.{uuid}`. */
export interface RoomPlanChangedEvent {
  layout_version: number | null
  revision: number | null
  cause: 'layout_saved' | 'layout_created' | 'layout_activated' | 'roster'
  by: { name: string } | null
}

/** `PcStatusChanged` on `floor-plan.room.{uuid}`. */
export interface PcStatusChangedEvent {
  pc: { id: string; name: string; unit_code: string; status: PcStatus }
}

/* ── Room overview ─────────────────────────────────────────────────────── */

export interface RoomStatusCount {
  value: PcStatusValue
  label: string
  tone: Tone
  count: number
}

export interface FloorPlanRoomSummary {
  id: string
  name: string
  code: string
  room_type: { value: string; label: string }
  is_active: boolean
  capacity: number | null
  floor: { name: string; floor_number: number } | null
  building: { name: string; code: string } | null
  pcs: { total: number; placed: number; by_status: RoomStatusCount[] }
  layout: {
    version: number
    revision: number
    width: number
    height: number
    updated_at: string | null
    updated_by: { name: string } | null
  } | null
  /** Integer-rounded thumbnail geometry: pcs [x, y, rotation, status]; fixtures [kind, x, y, w, h, rotation]. */
  preview: {
    pcs: [number, number, number, PcStatusValue][]
    fixtures: [FixtureKind, number, number, number, number, number][]
    truncated: boolean
  } | null
}

export interface FloorPlanRoomsResponse {
  data: FloorPlanRoomSummary[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    unassigned_pcs: number
  }
}

export interface FloorPlanRoomsParams {
  search?: string
  building?: string
  room_type?: string
  layout?: 'set_up' | 'not_set_up'
  sort?: 'name' | 'pcs' | 'attention'
  page?: number
  per_page?: number
}

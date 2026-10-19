import type { PcStatus, PcStatusValue, PlacedPc, RoomPlan, Tone, UnplacedPc } from '../types'

/**
 * Test data shaped exactly like the API payload. The label and tone pairs are
 * the ones `PcStatus::label()/tone()` produce on the server — the client never
 * derives them, so tests state them rather than compute them.
 */
const STATUSES: Record<PcStatusValue, PcStatus> = {
  available: { value: 'available', label: 'Available', tone: 'success' },
  pending_repair: { value: 'pending_repair', label: 'Pending Repair', tone: 'warning' },
  needs_fix: { value: 'needs_fix', label: 'Needs Fix', tone: 'danger' },
}

export const ALL_STATUS_VALUES = Object.keys(STATUSES) as PcStatusValue[]

export function status(value: PcStatusValue): PcStatus {
  return STATUSES[value]
}

export function pc(
  index: number,
  value: PcStatusValue,
  overrides: Partial<PlacedPc> = {},
): PlacedPc {
  return {
    id: `pc-uuid-${index}`,
    name: `PC-${String(index).padStart(2, '0')}`,
    unit_code: `LAB-${index}`,
    status: STATUSES[value],
    x: 100 * index,
    y: 120,
    rotation: 0,
    z_index: 0,
    // WP-F: the optimistic-concurrency token. Distinct per index by default so
    // a test that forgets to override it on a second unit doesn't accidentally
    // share one.
    updated_at: `2026-01-01T00:00:${String(index).padStart(2, '0')}Z`,
    open_ticket: null,
    ...overrides,
  }
}

export function roomPlan(overrides: Partial<RoomPlan> = {}): RoomPlan {
  return {
    room: {
      id: 'room-uuid-1',
      name: 'Computer Lab 2',
      code: 'LAB-2',
      floor: { name: 'Ground floor', floor_number: 1 },
      building: { name: 'Main Building', code: 'MAIN' },
    },
    layout: { version: 1, width: 1000, height: 600, grid_size: 20 },
    pcs: ALL_STATUS_VALUES.map((value, i) => pc(i + 1, value)),
    unplaced: [],
    unplaced_count: 0,
    editor: { can_edit: false, snap_to_grid: true },
    ...overrides,
  }
}

/** A unit of the room that has no position yet. */
export function unplacedPc(index: number, value: PcStatusValue = 'available'): UnplacedPc {
  return {
    id: `unplaced-uuid-${index}`,
    name: `PC-U${index}`,
    unit_code: `LAB-U${index}`,
    status: STATUSES[value],
  }
}

export type { Tone }

import type {
  Fixture,
  FloorPlanRoomSummary,
  PcStatus,
  PcStatusValue,
  PlacedPc,
  RoomPlan,
  UnplacedPc,
} from '../types'

/**
 * Test data shaped exactly like the API payload. Label and tone pairs are the
 * ones `PcStatus::label()/tone()` produce on the server — stated, not derived.
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
    updated_at: `2026-01-01T00:00:${String(index).padStart(2, '0')}Z`,
    open_ticket: null,
    ...overrides,
  }
}

export function unplacedPc(index: number, value: PcStatusValue = 'available'): UnplacedPc {
  return {
    id: `unplaced-uuid-${index}`,
    name: `PC-U${index}`,
    unit_code: `LAB-U${index}`,
    status: STATUSES[value],
  }
}

export function fixture(overrides: Partial<Fixture> = {}): Fixture {
  return {
    id: 'fixture-uuid-1',
    kind: 'desk',
    x: 500,
    y: 400,
    width: 80,
    height: 40,
    rotation: 0,
    label: null,
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
    layout: {
      version: 1,
      revision: 3,
      width: 1000,
      height: 600,
      grid_size: 20,
      updated_at: '2026-10-19T08:00:00+00:00',
      updated_by: { name: 'Ana Reyes' },
    },
    fixtures: [],
    workstation_size: 40,
    pcs: ALL_STATUS_VALUES.map((value, i) => pc(i + 1, value)),
    unplaced: [],
    unplaced_count: 0,
    editor: { can_edit: true, snap_to_grid: true },
    ...overrides,
  }
}

export function roomSummary(overrides: Partial<FloorPlanRoomSummary> = {}): FloorPlanRoomSummary {
  return {
    id: 'room-uuid-1',
    name: 'Computer Lab 2',
    code: 'LAB-2',
    room_type: { value: 'laboratory', label: 'Laboratory' },
    is_active: true,
    capacity: 40,
    floor: { name: 'Ground floor', floor_number: 1 },
    building: { name: 'Main Building', code: 'MAIN' },
    pcs: {
      total: 3,
      placed: 2,
      by_status: [
        { ...STATUSES.available, count: 1 },
        { ...STATUSES.pending_repair, count: 1 },
        { ...STATUSES.needs_fix, count: 1 },
      ],
    },
    layout: {
      version: 1,
      revision: 3,
      width: 1000,
      height: 600,
      updated_at: '2026-10-19T08:00:00+00:00',
      updated_by: { name: 'Ana Reyes' },
    },
    preview: {
      pcs: [[100, 120, 0, 'available']],
      fixtures: [['desk', 100, 120, 80, 40, 0]],
      truncated: false,
    },
    ...overrides,
  }
}

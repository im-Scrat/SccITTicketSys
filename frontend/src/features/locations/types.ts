/** Room types mirror the backend `RoomType` enum (varchar + CHECK domain). */
export type RoomType =
  'laboratory' | 'office' | 'storage' | 'server_room' | 'faculty_room' | 'library' | 'other'

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export interface BuildingRef {
  id: string | null
  name: string | null
  code?: string | null
  is_active?: boolean | null
  archived?: boolean
}

export interface FloorRef {
  id: string | null
  floor_number: number | null
  name: string | null
  archived?: boolean
}

export interface BuildingListItem {
  id: string
  name: string
  code: string
  address: string | null
  is_active: boolean
  floors_count: number
  rooms_count: number
  created_at: string | null
  archived: boolean
}

export interface FloorItem {
  id: string
  floor_number: number
  name: string
  description: string | null
  rooms_count: number
  building?: BuildingRef
  created_at: string | null
  updated_at: string | null
  archived: boolean
  archived_at: string | null
}

export interface BuildingDetail {
  id: string
  name: string
  code: string
  description: string | null
  address: string | null
  is_active: boolean
  floors_count: number
  rooms_count: number
  floors?: FloorItem[]
  created_by: string | null
  updated_by: string | null
  created_at: string | null
  updated_at: string | null
  archived: boolean
  archived_at: string | null
}

export interface RoomListItem {
  id: string
  name: string
  code: string
  room_number: string | null
  room_type: RoomType
  room_type_label: string
  capacity: number | null
  is_active: boolean
  pc_units_count: number
  floor?: FloorRef
  building?: BuildingRef
  created_at: string | null
  archived: boolean
}

export interface RoomDetail {
  id: string
  name: string
  code: string
  room_number: string | null
  room_type: RoomType
  room_type_label: string
  capacity: number | null
  description: string | null
  is_active: boolean
  pc_units_count: number
  assets_count: number
  consumables_count: number
  tickets_count: number
  floor: FloorRef | null
  building: BuildingRef | null
  selectable: boolean
  created_by: string | null
  updated_by: string | null
  created_at: string | null
  updated_at: string | null
  archived: boolean
  archived_at: string | null
}

/** The FR-LOC-004 in-use report the API attaches under `meta`. */
export interface Blockers {
  pc_units: number
  assets: number
  consumables: number
  open_tickets: number
}

export interface LocationMeta {
  blockers: Blockers
  in_use: boolean
}

export interface Detail<T> {
  data: T
  meta?: LocationMeta
  message?: string
}

/** The 422 payload raised when an archive would strand occupants. */
export interface LocationInUseError {
  message: string
  code: 'location_in_use'
  level: 'building' | 'floor' | 'room'
  blockers: Blockers
  rooms: Array<{ id: string; name: string; label: string; blockers: Blockers }>
}

export interface LocationTreeNode {
  id: string
  name: string
  code: string
  is_active: boolean
  floors_count: number
  rooms_count: number
  floors: Array<{
    id: string
    floor_number: number
    name: string
    rooms_count: number
  }>
}

export interface LocationOption {
  id: string
  name: string
  code: string
  room_type: RoomType
  room_type_label: string
  floor: { id: string; floor_number: number; name: string } | null
  building: { id: string; name: string } | null
  /** Pre-composed "Building · Floor · Room" label. */
  label: string
}

export interface BuildingOption {
  id: string
  name: string
  code: string
}

export interface FloorOption {
  id: string
  floor_number: number
  name: string
  building: { id: string | null; name: string | null }
}

export interface LocationMetrics {
  summary: {
    buildings: number
    buildings_active: number
    buildings_inactive: number
    buildings_archived: number
    floors: number
    floors_archived: number
    rooms: number
    rooms_active: number
    rooms_inactive: number
    rooms_archived: number
    total_capacity: number
  }
  by_room_type: Array<{ value: RoomType; label: string; count: number }>
  occupancy: {
    pc_units_placed: number
    pc_units_unplaced: number
    rooms_with_pc_units: number
    empty_rooms: number
    buildings_without_rooms: number
  }
  recent_activity: Array<{
    actor: { id: string; name: string } | null
    action: string
    label: string
    description: string | null
    at: string | null
  }>
}

export type BuildingSortColumn = 'name' | 'code' | 'floors_count' | 'rooms_count' | 'created_at'

export type RoomSortColumn =
  | 'name'
  | 'code'
  | 'room_type'
  | 'capacity'
  | 'building'
  | 'floor_number'
  | 'pc_units_count'
  | 'created_at'

export type TrashedFilter = 'without' | 'with' | 'only'

export type ActiveFilter = 'all' | 'active' | 'inactive'

export interface BuildingParams {
  search?: string
  active?: ActiveFilter
  trashed?: TrashedFilter
  sort?: BuildingSortColumn
  direction?: 'asc' | 'desc'
  per_page?: number
  page?: number
}

export interface RoomParams {
  search?: string
  building?: string
  floor?: string
  room_type?: RoomType | 'all'
  active?: ActiveFilter
  trashed?: TrashedFilter
  sort?: RoomSortColumn
  direction?: 'asc' | 'desc'
  per_page?: number
  page?: number
}

import { api } from '@/services/api'

/**
 * The narrow location lookup (SRS FR-LOC-011).
 *
 * Deliberately *not* part of the `features/locations` slice: that slice is the
 * Administrator-only Locations module, and a ticket or maintenance form must be
 * able to offer a room field without importing any of it. Anything reachable by a
 * non-administrator lives here, and it is only ever labels.
 *
 * Server-side, `/api/lookups/*` is authorized by the permission of the workflow
 * that needs the field (tickets.create, maintenance.view, assets.transfer, …),
 * never by a `locations.*` permission.
 */

export interface RoomLookupOption {
  id: string
  name: string
  code: string
  room_type: string
  room_type_label: string
  floor: { id: string; floor_number: number; name: string } | null
  building: { id: string; name: string } | null
  /** Pre-composed "Building · Floor · Room" label. */
  label: string
}

export interface BuildingLookupOption {
  id: string
  name: string
  code: string
}

export interface FloorLookupOption {
  id: string
  floor_number: number
  name: string
  building: { id: string | null; name: string | null }
}

export interface RoomLookupParams {
  search?: string
  building?: string
  floor?: string
  room_type?: string
  limit?: number
}

function clean(params: object): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[key] = value as string | number
  }
  return out
}

export async function lookupRooms(params: RoomLookupParams = {}): Promise<RoomLookupOption[]> {
  const { data } = await api.get<{ data: RoomLookupOption[] }>('/lookups/rooms', {
    params: clean(params),
  })
  return data.data
}

export async function lookupBuildings(): Promise<BuildingLookupOption[]> {
  const { data } = await api.get<{ data: BuildingLookupOption[] }>('/lookups/buildings')
  return data.data
}

export async function lookupFloors(building?: string): Promise<FloorLookupOption[]> {
  const { data } = await api.get<{ data: FloorLookupOption[] }>('/lookups/floors', {
    params: clean({ building }),
  })
  return data.data
}

/**
 * The narrow equipment lookup (SDD DD-38) — the asset counterpart of the room
 * lookup above, and the only asset data a non-administrator can reach.
 *
 * Labels only: an id, a readable name, an identifier and where it is. There is
 * no status, condition, price, supplier, custodian or warranty here, because
 * the server resource has no field that could carry one. It is authorized by
 * `tickets.create` (and the other consuming workflows), never by `assets.*`, so
 * a teacher can name the PC they are reporting without gaining any access to
 * the Asset Management module.
 */
export interface EquipmentLookupOption {
  id: string
  label: string
  identifier: string | null
  location: string | null
}

export async function lookupPcUnits(search?: string): Promise<EquipmentLookupOption[]> {
  const { data } = await api.get<{ data: EquipmentLookupOption[] }>('/lookups/pc-units', {
    params: clean({ search }),
  })
  return data.data
}

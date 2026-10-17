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
  /**
   * `type` puts laboratories first, then offices, then every other room — the
   * order a person reporting a fault is most likely to want. Ordered on the
   * server because the list is capped: sorting a truncated list on the client
   * would hide the very rooms it meant to bring forward.
   */
  sort?: 'type'
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

/**
 * Selectable serialized assets — the sibling of {@link lookupPcUnits}.
 *
 * Added in WP-2.6 for the hardware-replacement form, which has to name the unit
 * that came out and the unit that went in. Same endpoint family, same narrow
 * contract: labels only, authorized by the consuming workflow's permission
 * (`maintenance.*`) and never by an `assets.*` one.
 */
export async function lookupAssets(search?: string): Promise<EquipmentLookupOption[]> {
  const { data } = await api.get<{ data: EquipmentLookupOption[] }>('/lookups/assets', {
    params: clean({ search }),
  })
  return data.data
}

/** The most the room-scoped lookup returns per list; the server refuses more. */
export const ROOM_EQUIPMENT_LIMIT = 200

/** One room's reportable equipment, as two label-only lists. */
export interface RoomEquipmentLookup {
  pc_units: EquipmentLookupOption[]
  assets: EquipmentLookupOption[]
}

/**
 * What can be reported in one room (SDD DD-78) — the third step of the report
 * form's building → room → item cascade.
 *
 * Same narrow contract as the two lookups above, scoped to a room: its PCs that
 * are not retired, and its other equipment that is in service. There is **no
 * search parameter**: the server matches nothing but the room, so the endpoint
 * cannot be used to probe the serial numbers or hostnames its labels withhold.
 * Filtering a long list is done here, on the labels already received.
 *
 * A room this caller could not have picked — unknown, inactive, archived —
 * answers with two empty lists, the same as an empty room, so the response
 * says nothing about whether the room exists.
 */
export async function lookupRoomEquipment(
  room: string,
  signal?: AbortSignal,
): Promise<RoomEquipmentLookup> {
  const { data } = await api.get<{ data: RoomEquipmentLookup }>('/lookups/equipment', {
    params: { room, limit: ROOM_EQUIPMENT_LIMIT },
    signal,
  })
  return data.data
}

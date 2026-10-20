import type { RoomPlan, UnplacedEquipment } from '../types'

/**
 * Equipment on the Floorplan (owner request 2026-10-20, SDD DD-115).
 *
 * A confirmed asset is placed, dragged, turned and saved exactly like a
 * workstation — the server holds it to the same footprint and overlap rules —
 * so the editor keeps its geometry in the draft's `workstations` map under a
 * prefixed id, `eq:<asset uuid>`. Every edit path (place, drag, nudge, turn,
 * remove, undo, merge, arrange) then works on it unchanged, and only the
 * places that need to know what an id *is* ask {@see isEquipmentId}: drawing,
 * the details panel, and splitting the save request back into `workstations`
 * and `equipment`. A PC's id is a bare uuid, so the two can never collide.
 */

export const EQUIPMENT_PREFIX = 'eq:'

export function isEquipmentId(id: string): boolean {
  return id.startsWith(EQUIPMENT_PREFIX)
}

export function equipmentDraftId(assetUuid: string): string {
  return `${EQUIPMENT_PREFIX}${assetUuid}`
}

export function assetUuidOf(draftId: string): string {
  return draftId.slice(EQUIPMENT_PREFIX.length)
}

/** Every confirmed item of this room, placed on the server or not, keyed by draft id. */
export function equipmentRoster(plan: RoomPlan): Map<string, UnplacedEquipment> {
  const out = new Map<string, UnplacedEquipment>()
  for (const item of plan.unplaced_equipment ?? []) out.set(equipmentDraftId(item.id), item)
  for (const item of plan.equipment ?? []) out.set(equipmentDraftId(item.id), item)
  return out
}
